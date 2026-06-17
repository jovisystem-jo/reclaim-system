#!/usr/bin/env python3
from __future__ import annotations
"""
Production image comparator for RECLAIM.

Scoring model:
- ORB keypoint matching on the detected foreground object
- Color similarity using masked HSV histograms + LAB palette comparison
- Shape similarity using normalized foreground silhouettes
- Final similarity only reaches high-confidence ranges when ORB, color, and
  shape agree together
"""

import json
import os
import sys
from typing import Dict, List, Optional, Tuple

IMPORT_ERROR = None

try:
    import cv2
    import numpy as np
except Exception as exc:  # pragma: no cover - runtime safeguard
    cv2 = None
    np = None
    IMPORT_ERROR = exc

MAX_DIMENSION = 1200
LOWE_RATIO = 0.72
RANSAC_REPROJECTION_THRESHOLD = 4.0
ORB_WEIGHT = 0.40
COLOR_WEIGHT = 0.35
SHAPE_WEIGHT = 0.25
PALETTE_CLUSTERS = 3
MAX_KMEANS_PIXELS = 5000


def log_debug(message: str) -> None:
    """Write debug information to stderr without polluting stdout JSON."""
    print(message, file=sys.stderr)


def build_error_result(message: str) -> Dict[str, float]:
    """Return a consistent JSON-safe error payload."""
    return {
        "error": message,
        "similarity": 0.0,
        "orb_score": 0.0,
        "histogram_score": 0.0,
        "shape_score": 0.0,
        "verified_matches": 0,
        "keypoints_image1": 0,
        "keypoints_image2": 0,
        # Compatibility aliases for existing tools/pages.
        "hist_score": 0.0,
        "features1": 0,
        "features2": 0,
    }


def clamp_score(value: float) -> float:
    """Clamp a numeric score to the 0-100 range."""
    return max(0.0, min(100.0, float(value)))


def resize_if_needed(image: np.ndarray, max_dimension: int = MAX_DIMENSION) -> np.ndarray:
    """Resize large images while preserving aspect ratio."""
    height, width = image.shape[:2]
    largest_dimension = max(height, width)

    if largest_dimension <= max_dimension:
        return image

    scale = max_dimension / float(largest_dimension)
    new_width = max(1, int(round(width * scale)))
    new_height = max(1, int(round(height * scale)))

    return cv2.resize(image, (new_width, new_height), interpolation=cv2.INTER_AREA)


def calculate_mask_coverage(mask: np.ndarray) -> float:
    """Return the fraction of pixels covered by a binary mask."""
    if mask is None or mask.size == 0:
        return 0.0

    return float(np.count_nonzero(mask)) / float(mask.size)


def collect_border_pixels(image: np.ndarray, thickness_ratio: float = 0.08) -> np.ndarray:
    """Sample pixels from the border to estimate the background color."""
    height, width = image.shape[:2]
    thickness = max(2, int(round(min(height, width) * thickness_ratio)))

    slices = [
        image[:thickness, :, :],
        image[-thickness:, :, :],
        image[:, :thickness, :],
        image[:, -thickness:, :],
    ]

    pixels = [slc.reshape(-1, 3) for slc in slices if slc.size > 0]
    if not pixels:
        return image.reshape(-1, 3)

    return np.vstack(pixels)


def cleanup_binary_mask(mask: np.ndarray) -> np.ndarray:
    """Clean a binary mask and keep the most relevant connected components."""
    binary = np.where(mask > 0, 255, 0).astype(np.uint8)
    kernel = np.ones((5, 5), np.uint8)
    binary = cv2.morphologyEx(binary, cv2.MORPH_OPEN, kernel)
    binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel)

    component_count, labels, stats, _ = cv2.connectedComponentsWithStats(binary, connectivity=8)
    if component_count <= 1:
        return binary

    areas = stats[1:, cv2.CC_STAT_AREA]
    if len(areas) == 0:
        return binary

    largest_area = int(areas.max())
    minimum_area = max(int(binary.size * 0.005), 64)
    cleaned = np.zeros_like(binary)

    for label_index in range(1, component_count):
        area = int(stats[label_index, cv2.CC_STAT_AREA])
        if area < minimum_area and area < int(largest_area * 0.15):
            continue

        cleaned[labels == label_index] = 255

    if np.count_nonzero(cleaned) == 0:
        largest_label = int(np.argmax(areas)) + 1
        cleaned[labels == largest_label] = 255

    return cleaned


def build_threshold_fallback_mask(image: np.ndarray) -> np.ndarray:
    """Generate a fallback mask using Otsu thresholding."""
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    gray = cv2.GaussianBlur(gray, (5, 5), 0)

    _, otsu_mask = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    candidates = [
        cleanup_binary_mask(otsu_mask),
        cleanup_binary_mask(cv2.bitwise_not(otsu_mask)),
    ]

    chosen = None
    chosen_distance = None
    for candidate in candidates:
        coverage = calculate_mask_coverage(candidate)
        if coverage < 0.02 or coverage > 0.90:
            continue

        distance = abs(coverage - 0.35)
        if chosen is None or distance < float(chosen_distance):
            chosen = candidate
            chosen_distance = distance

    if chosen is not None:
        return chosen

    return np.full(gray.shape, 255, dtype=np.uint8)


def build_foreground_mask(image: np.ndarray) -> np.ndarray:
    """Estimate the main object mask from the image."""
    lab_image = cv2.cvtColor(image, cv2.COLOR_BGR2LAB).astype(np.float32)
    hsv_image = cv2.cvtColor(image, cv2.COLOR_BGR2HSV).astype(np.float32)

    border_pixels = collect_border_pixels(lab_image)
    border_median = np.median(border_pixels, axis=0)
    color_distance = np.linalg.norm(lab_image - border_median, axis=2)

    adaptive_threshold = max(14.0, float(np.percentile(color_distance, 68)) * 0.85)
    saturation = hsv_image[:, :, 1]
    value = hsv_image[:, :, 2]

    mask = np.where(
        (color_distance >= adaptive_threshold) | (saturation >= 26.0) | (value <= 238.0),
        255,
        0,
    ).astype(np.uint8)
    mask = cleanup_binary_mask(mask)

    coverage = calculate_mask_coverage(mask)
    if coverage < 0.03 or coverage > 0.90:
        mask = build_threshold_fallback_mask(image)
        coverage = calculate_mask_coverage(mask)

    if coverage < 0.01:
        return np.full(mask.shape, 255, dtype=np.uint8)

    return mask


def load_image(image_path: str) -> Tuple[np.ndarray, np.ndarray, np.ndarray]:
    """Load an image in color, grayscale, and foreground-mask forms."""
    if not os.path.isfile(image_path):
        raise FileNotFoundError(f"Image not found: {image_path}")

    color_image = cv2.imread(image_path, cv2.IMREAD_COLOR)
    if color_image is None:
        raise ValueError(f"Unable to load image: {image_path}")

    color_image = resize_if_needed(color_image)
    grayscale_image = cv2.cvtColor(color_image, cv2.COLOR_BGR2GRAY)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    grayscale_image = clahe.apply(grayscale_image)
    foreground_mask = build_foreground_mask(color_image)

    return color_image, grayscale_image, foreground_mask


def create_orb() -> cv2.ORB:
    """Create the requested ORB detector configuration."""
    return cv2.ORB_create(
        nfeatures=3500,
        scaleFactor=1.2,
        nlevels=8,
        edgeThreshold=19,
        patchSize=31,
        fastThreshold=8,
    )


def extract_features(grayscale_image: np.ndarray, foreground_mask: Optional[np.ndarray]) -> Tuple[List[cv2.KeyPoint], np.ndarray]:
    """Extract ORB keypoints and descriptors."""
    orb = create_orb()
    usable_mask = None

    if foreground_mask is not None:
        coverage = calculate_mask_coverage(foreground_mask)
        if 0.02 <= coverage <= 0.92:
            usable_mask = foreground_mask

    keypoints, descriptors = orb.detectAndCompute(grayscale_image, usable_mask)
    return keypoints or [], descriptors


def get_good_matches(descriptors1: np.ndarray, descriptors2: np.ndarray) -> List[cv2.DMatch]:
    """Match descriptors using BFMatcher and Lowe's ratio test."""
    if descriptors1 is None or descriptors2 is None:
        return []

    if len(descriptors1) == 0 or len(descriptors2) == 0:
        return []

    matcher = cv2.BFMatcher(cv2.NORM_HAMMING)
    knn_matches = matcher.knnMatch(descriptors1, descriptors2, k=2)

    good_matches: List[cv2.DMatch] = []
    for pair in knn_matches:
        if len(pair) < 2:
            continue

        match_a, match_b = pair
        if match_a.distance < LOWE_RATIO * match_b.distance:
            good_matches.append(match_a)

    good_matches.sort(key=lambda match: float(match.distance))
    return good_matches[:250]


def verify_matches_with_homography(
    keypoints1: List[cv2.KeyPoint],
    keypoints2: List[cv2.KeyPoint],
    good_matches: List[cv2.DMatch],
) -> Tuple[int, List[cv2.DMatch], np.ndarray, np.ndarray]:
    """Count geometrically verified matches using RANSAC homography."""
    if len(good_matches) < 4:
        return 0, [], np.empty((0, 2), dtype=np.float32), np.empty((0, 2), dtype=np.float32)

    source_points = np.float32(
        [keypoints1[match.queryIdx].pt for match in good_matches]
    ).reshape(-1, 1, 2)
    destination_points = np.float32(
        [keypoints2[match.trainIdx].pt for match in good_matches]
    ).reshape(-1, 1, 2)

    homography, inlier_mask = cv2.findHomography(
        source_points,
        destination_points,
        cv2.RANSAC,
        RANSAC_REPROJECTION_THRESHOLD,
    )

    if homography is None or inlier_mask is None:
        return 0, [], np.empty((0, 2), dtype=np.float32), np.empty((0, 2), dtype=np.float32)

    mask_flags = inlier_mask.reshape(-1).astype(bool)
    inlier_matches = [good_matches[index] for index, keep in enumerate(mask_flags) if keep]
    inlier_points1 = source_points.reshape(-1, 2)[mask_flags] if np.any(mask_flags) else np.empty((0, 2), dtype=np.float32)
    inlier_points2 = destination_points.reshape(-1, 2)[mask_flags] if np.any(mask_flags) else np.empty((0, 2), dtype=np.float32)

    return len(inlier_matches), inlier_matches, inlier_points1, inlier_points2


def calculate_match_distance_quality(matches: List[cv2.DMatch]) -> float:
    """Score how strong the retained matches are based on Hamming distance."""
    if not matches:
        return 0.0

    median_distance = float(np.median([float(match.distance) for match in matches]))
    return clamp_score((1.0 - min(median_distance / 75.0, 1.0)) * 100.0)


def calculate_spatial_spread_score(points: np.ndarray, image_shape: Tuple[int, int]) -> float:
    """Reward matches that spread across the object instead of one tiny cluster."""
    if points is None or len(points) < 4:
        return 0.0

    image_height, image_width = image_shape[:2]
    image_area = float(max(image_height * image_width, 1))
    hull = cv2.convexHull(points.astype(np.float32).reshape(-1, 1, 2))
    hull_area = float(max(cv2.contourArea(hull), 0.0))

    target_area = max(image_area * 0.08, 1.0)
    return clamp_score(min(hull_area / target_area, 1.0) * 100.0)


def calculate_orb_score(
    keypoints1: List[cv2.KeyPoint],
    descriptors1: np.ndarray,
    image1_shape: Tuple[int, int],
    keypoints2: List[cv2.KeyPoint],
    descriptors2: np.ndarray,
    image2_shape: Tuple[int, int],
) -> Tuple[float, int]:
    """Compute the ORB score from verified inlier matches."""
    good_matches = get_good_matches(descriptors1, descriptors2)
    total_good_matches = len(good_matches)

    if total_good_matches == 0:
        return 0.0, 0

    verified_matches, inlier_matches, inlier_points1, inlier_points2 = verify_matches_with_homography(
        keypoints1,
        keypoints2,
        good_matches,
    )
    if verified_matches == 0:
        return 0.0, 0

    inlier_ratio_score = clamp_score((verified_matches / float(total_good_matches)) * 100.0)
    support_score = clamp_score(min(verified_matches / 35.0, 1.0) * 100.0)
    distance_quality_score = calculate_match_distance_quality(inlier_matches or good_matches)
    spread_score = (
        calculate_spatial_spread_score(inlier_points1, image1_shape)
        + calculate_spatial_spread_score(inlier_points2, image2_shape)
    ) / 2.0
    balance_score = clamp_score(
        (
            min(len(keypoints1), len(keypoints2))
            / float(max(max(len(keypoints1), len(keypoints2)), 1))
        )
        * 100.0
    )

    orb_score = (
        (inlier_ratio_score * 0.45)
        + (support_score * 0.20)
        + (distance_quality_score * 0.15)
        + (spread_score * 0.15)
        + (balance_score * 0.05)
    )

    if verified_matches < 6:
        orb_score = min(orb_score, 40.0)
    elif verified_matches < 8:
        orb_score = min(orb_score, 58.0)

    return clamp_score(orb_score), verified_matches


def sample_masked_pixels(image: np.ndarray, mask: Optional[np.ndarray], max_samples: int = MAX_KMEANS_PIXELS) -> np.ndarray:
    """Collect masked BGR pixels and downsample for fast analysis."""
    if mask is not None and np.count_nonzero(mask) >= 30:
        pixels = image[mask > 0]
    else:
        pixels = image.reshape(-1, 3)

    if pixels.size == 0:
        return image.reshape(-1, 3)

    if len(pixels) > max_samples:
        step = max(int(len(pixels) / max_samples), 1)
        pixels = pixels[::step]

    return pixels


def convert_bgr_pixels_to_lab(pixels: np.ndarray) -> np.ndarray:
    """Convert a 2-D BGR pixel array to LAB."""
    if pixels.size == 0:
        return np.empty((0, 3), dtype=np.float32)

    reshaped = pixels.reshape(-1, 1, 3).astype(np.uint8)
    return cv2.cvtColor(reshaped, cv2.COLOR_BGR2LAB).reshape(-1, 3).astype(np.float32)


def calculate_palette_signature(image: np.ndarray, mask: Optional[np.ndarray]) -> Optional[Tuple[np.ndarray, np.ndarray]]:
    """Extract a compact dominant-color palette in LAB space."""
    pixels = sample_masked_pixels(image, mask)
    if len(pixels) == 0:
        return None

    lab_pixels = convert_bgr_pixels_to_lab(pixels)
    if len(lab_pixels) == 0:
        return None

    cluster_count = max(1, min(PALETTE_CLUSTERS, len(lab_pixels)))
    if cluster_count == 1:
        return lab_pixels[:1], np.array([1.0], dtype=np.float32)

    criteria = (cv2.TERM_CRITERIA_EPS + cv2.TERM_CRITERIA_MAX_ITER, 20, 0.5)
    _, labels, centers = cv2.kmeans(
        lab_pixels,
        cluster_count,
        None,
        criteria,
        3,
        cv2.KMEANS_PP_CENTERS,
    )

    labels = labels.reshape(-1)
    weights = np.bincount(labels, minlength=cluster_count).astype(np.float32)
    weights /= max(float(weights.sum()), 1.0)
    ordering = np.argsort(-weights)

    return centers[ordering], weights[ordering]


def calculate_palette_similarity(
    palette1: Optional[Tuple[np.ndarray, np.ndarray]],
    palette2: Optional[Tuple[np.ndarray, np.ndarray]],
) -> float:
    """Compare two LAB palettes using nearest-color and weight agreement."""
    if palette1 is None or palette2 is None:
        return 0.0

    centers1, weights1 = palette1
    centers2, weights2 = palette2
    if len(centers1) == 0 or len(centers2) == 0:
        return 0.0

    max_distance = float(np.sqrt(3 * (255.0 ** 2)))

    def directional_score(centers_a: np.ndarray, weights_a: np.ndarray, centers_b: np.ndarray, weights_b: np.ndarray) -> float:
        score = 0.0
        for index, center in enumerate(centers_a):
            distances = np.linalg.norm(centers_b - center, axis=1)
            nearest_index = int(np.argmin(distances))
            color_similarity = 1.0 - min(float(distances[nearest_index]) / max_distance, 1.0)
            weight_similarity = 1.0 - min(abs(float(weights_a[index]) - float(weights_b[nearest_index])), 1.0)
            score += float(weights_a[index]) * ((color_similarity * 0.85) + (weight_similarity * 0.15))
        return score

    similarity = (
        directional_score(centers1, weights1, centers2, weights2)
        + directional_score(centers2, weights2, centers1, weights1)
    ) / 2.0

    return clamp_score(similarity * 100.0)


def calculate_histogram_score(
    image1: np.ndarray,
    image2: np.ndarray,
    mask1: Optional[np.ndarray],
    mask2: Optional[np.ndarray],
) -> float:
    """Compare images using masked HSV histograms plus palette and LAB agreement."""
    hsv_image1 = cv2.cvtColor(image1, cv2.COLOR_BGR2HSV)
    hsv_image2 = cv2.cvtColor(image2, cv2.COLOR_BGR2HSV)

    histogram1 = cv2.calcHist(
        [hsv_image1],
        [0, 1, 2],
        mask1,
        [18, 16, 16],
        [0, 180, 0, 256, 0, 256],
    )
    histogram2 = cv2.calcHist(
        [hsv_image2],
        [0, 1, 2],
        mask2,
        [18, 16, 16],
        [0, 180, 0, 256, 0, 256],
    )

    cv2.normalize(histogram1, histogram1, 0, 1, cv2.NORM_MINMAX)
    cv2.normalize(histogram2, histogram2, 0, 1, cv2.NORM_MINMAX)

    histogram_distance = cv2.compareHist(histogram1, histogram2, cv2.HISTCMP_BHATTACHARYYA)
    histogram_similarity = clamp_score((1.0 - min(float(histogram_distance), 1.0)) * 100.0)

    lab_pixels1 = convert_bgr_pixels_to_lab(sample_masked_pixels(image1, mask1))
    lab_pixels2 = convert_bgr_pixels_to_lab(sample_masked_pixels(image2, mask2))
    if len(lab_pixels1) == 0 or len(lab_pixels2) == 0:
        return histogram_similarity

    mean1 = lab_pixels1.mean(axis=0)
    mean2 = lab_pixels2.mean(axis=0)

    color_distance_scale = 220.0
    mean_color_similarity = clamp_score(
        (1.0 - min(float(np.linalg.norm(mean1 - mean2)) / color_distance_scale, 1.0)) * 100.0
    )

    hsv_pixels1 = sample_masked_pixels(hsv_image1, mask1)
    hsv_pixels2 = sample_masked_pixels(hsv_image2, mask2)
    mean_hsv1 = hsv_pixels1.mean(axis=0) if len(hsv_pixels1) > 0 else np.zeros(3, dtype=np.float32)
    mean_hsv2 = hsv_pixels2.mean(axis=0) if len(hsv_pixels2) > 0 else np.zeros(3, dtype=np.float32)

    saturation_similarity = clamp_score((1.0 - min(abs(float(mean_hsv1[1]) - float(mean_hsv2[1])) / 255.0, 1.0)) * 100.0)
    value_similarity = clamp_score((1.0 - min(abs(float(mean_hsv1[2]) - float(mean_hsv2[2])) / 255.0, 1.0)) * 100.0)
    palette_similarity = calculate_palette_similarity(
        calculate_palette_signature(image1, mask1),
        calculate_palette_signature(image2, mask2),
    )

    similarity = (
        (histogram_similarity * 0.45)
        + (mean_color_similarity * 0.20)
        + (value_similarity * 0.15)
        + (saturation_similarity * 0.10)
        + (palette_similarity * 0.10)
    )

    if histogram_similarity < 30.0 and value_similarity < 55.0:
        similarity *= 0.70
    elif histogram_similarity < 30.0 and saturation_similarity < 70.0:
        similarity *= 0.82
    elif palette_similarity < 35.0 and mean_color_similarity < 40.0 and histogram_similarity < 50.0:
        similarity *= 0.72

    return clamp_score(similarity)


def extract_largest_contour(mask: np.ndarray) -> Optional[np.ndarray]:
    """Return the largest meaningful contour from a binary mask."""
    if mask is None or np.count_nonzero(mask) == 0:
        return None

    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not contours:
        return None

    image_area = float(mask.shape[0] * mask.shape[1])
    valid_contours = [contour for contour in contours if cv2.contourArea(contour) >= image_area * 0.005]
    if not valid_contours:
        valid_contours = contours

    return max(valid_contours, key=cv2.contourArea)


def normalize_contour_mask(mask: np.ndarray, contour: np.ndarray, size: int = 160) -> Optional[np.ndarray]:
    """Crop a contour mask and place it onto a square canvas for IoU comparison."""
    x, y, width, height = cv2.boundingRect(contour)
    if width <= 0 or height <= 0:
        return None

    cropped = mask[y:y + height, x:x + width]
    if cropped.size == 0:
        return None

    padding = 8
    target = size - (padding * 2)
    scale = min(target / float(width), target / float(height))
    new_width = max(1, int(round(width * scale)))
    new_height = max(1, int(round(height * scale)))

    resized = cv2.resize(cropped, (new_width, new_height), interpolation=cv2.INTER_NEAREST)
    canvas = np.zeros((size, size), dtype=np.uint8)
    offset_x = (size - new_width) // 2
    offset_y = (size - new_height) // 2
    canvas[offset_y:offset_y + new_height, offset_x:offset_x + new_width] = resized

    return np.where(canvas > 0, 255, 0).astype(np.uint8)


def calculate_mask_iou(mask1: Optional[np.ndarray], mask2: Optional[np.ndarray]) -> float:
    """Compute a silhouette IoU score."""
    if mask1 is None or mask2 is None:
        return 0.0

    binary1 = mask1 > 0
    binary2 = mask2 > 0
    union = int(np.count_nonzero(binary1 | binary2))
    if union == 0:
        return 0.0

    intersection = int(np.count_nonzero(binary1 & binary2))
    return clamp_score((intersection / float(union)) * 100.0)


def calculate_shape_score(mask1: np.ndarray, mask2: np.ndarray) -> float:
    """Compare normalized foreground silhouettes from two masks."""
    contour1 = extract_largest_contour(mask1)
    contour2 = extract_largest_contour(mask2)

    if contour1 is None or contour2 is None:
        return 0.0

    match_distance = float(cv2.matchShapes(contour1, contour2, cv2.CONTOURS_MATCH_I1, 0.0))
    match_shape_similarity = clamp_score((1.0 / (1.0 + (match_distance * 6.0))) * 100.0)

    area1 = float(max(cv2.contourArea(contour1), 1.0))
    area2 = float(max(cv2.contourArea(contour2), 1.0))
    x1, y1, width1, height1 = cv2.boundingRect(contour1)
    x2, y2, width2, height2 = cv2.boundingRect(contour2)

    aspect1 = float(width1) / float(max(height1, 1))
    aspect2 = float(width2) / float(max(height2, 1))
    aspect_ratio_delta = abs(np.log((aspect1 + 1e-6) / (aspect2 + 1e-6)))
    aspect_similarity = clamp_score((1.0 - min(float(aspect_ratio_delta) / np.log(3.0), 1.0)) * 100.0)

    hull_area1 = float(max(cv2.contourArea(cv2.convexHull(contour1)), 1.0))
    hull_area2 = float(max(cv2.contourArea(cv2.convexHull(contour2)), 1.0))
    solidity1 = area1 / hull_area1
    solidity2 = area2 / hull_area2
    solidity_similarity = clamp_score((1.0 - min(abs(solidity1 - solidity2), 1.0)) * 100.0)

    extent1 = area1 / float(max(width1 * height1, 1))
    extent2 = area2 / float(max(width2 * height2, 1))
    extent_similarity = clamp_score((1.0 - min(abs(extent1 - extent2), 1.0)) * 100.0)

    normalized_mask1 = normalize_contour_mask(mask1, contour1)
    normalized_mask2 = normalize_contour_mask(mask2, contour2)
    mask_iou_similarity = calculate_mask_iou(normalized_mask1, normalized_mask2)

    similarity = (
        (match_shape_similarity * 0.40)
        + (mask_iou_similarity * 0.35)
        + (aspect_similarity * 0.15)
        + (((solidity_similarity + extent_similarity) / 2.0) * 0.10)
    )

    return clamp_score(similarity)


def apply_similarity_adjustments(
    base_similarity: float,
    orb_score: float,
    histogram_score: float,
    shape_score: float,
    verified_matches: int,
) -> float:
    """Apply penalties/boosts so high scores require agreement across signals."""
    similarity = float(base_similarity)

    if histogram_score < 30.0 and shape_score >= 70.0 and orb_score < 50.0:
        similarity *= 0.72
    elif histogram_score < 35.0 and orb_score < 50.0:
        similarity *= 0.78

    if shape_score < 25.0 and orb_score < 35.0:
        similarity *= 0.82

    if verified_matches < 6 and orb_score < 35.0:
        similarity *= 0.85

    if orb_score >= 65.0 and histogram_score >= 70.0 and shape_score >= 65.0 and verified_matches >= 8:
        similarity = max(similarity, 80.0)

    if orb_score >= 80.0 and histogram_score >= 80.0 and shape_score >= 75.0 and verified_matches >= 12:
        similarity = max(similarity, 90.0)

    return clamp_score(similarity)


def build_success_result(
    similarity: float,
    orb_score: float,
    histogram_score: float,
    shape_score: float,
    verified_matches: int,
    keypoints_image1: int,
    keypoints_image2: int,
) -> Dict[str, float]:
    """Build the final JSON payload."""
    similarity = round(clamp_score(similarity), 2)
    orb_score = round(clamp_score(orb_score), 2)
    histogram_score = round(clamp_score(histogram_score), 2)
    shape_score = round(clamp_score(shape_score), 2)

    return {
        "similarity": similarity,
        "orb_score": orb_score,
        "histogram_score": histogram_score,
        "shape_score": shape_score,
        "verified_matches": int(verified_matches),
        "keypoints_image1": int(keypoints_image1),
        "keypoints_image2": int(keypoints_image2),
        # Compatibility aliases for existing tools/pages.
        "hist_score": histogram_score,
        "features1": int(keypoints_image1),
        "features2": int(keypoints_image2),
    }


def main() -> None:
    if len(sys.argv) != 3:
        print(json.dumps(build_error_result("Usage: compare.py <image1> <image2>")))
        return

    if IMPORT_ERROR is not None:
        log_debug(f"compare.py dependency error: {IMPORT_ERROR}")
        print(json.dumps(build_error_result(f"Python image comparison dependencies unavailable: {IMPORT_ERROR}")))
        return

    image_path_1 = sys.argv[1]
    image_path_2 = sys.argv[2]

    try:
        log_debug(f"Comparing images: {image_path_1} <> {image_path_2}")

        color_image1, grayscale_image1, foreground_mask1 = load_image(image_path_1)
        color_image2, grayscale_image2, foreground_mask2 = load_image(image_path_2)

        keypoints1, descriptors1 = extract_features(grayscale_image1, foreground_mask1)
        keypoints2, descriptors2 = extract_features(grayscale_image2, foreground_mask2)

        if os.path.abspath(image_path_1) == os.path.abspath(image_path_2):
            result = build_success_result(
                similarity=100.0,
                orb_score=100.0,
                histogram_score=100.0,
                shape_score=100.0,
                verified_matches=min(len(keypoints1), len(keypoints2)),
                keypoints_image1=len(keypoints1),
                keypoints_image2=len(keypoints2),
            )
            print(json.dumps(result))
            return

        orb_score, verified_matches = calculate_orb_score(
            keypoints1,
            descriptors1,
            grayscale_image1.shape,
            keypoints2,
            descriptors2,
            grayscale_image2.shape,
        )
        histogram_score = calculate_histogram_score(color_image1, color_image2, foreground_mask1, foreground_mask2)
        shape_score = calculate_shape_score(foreground_mask1, foreground_mask2)

        base_similarity = (
            (orb_score * ORB_WEIGHT)
            + (histogram_score * COLOR_WEIGHT)
            + (shape_score * SHAPE_WEIGHT)
        )
        similarity = apply_similarity_adjustments(
            base_similarity,
            orb_score,
            histogram_score,
            shape_score,
            verified_matches,
        )

        log_debug(
            "ORB={:.2f}, HIST={:.2f}, SHAPE={:.2f}, VERIFIED={}, KP1={}, KP2={}, MASK1={:.2f}, MASK2={:.2f}".format(
                orb_score,
                histogram_score,
                shape_score,
                verified_matches,
                len(keypoints1),
                len(keypoints2),
                calculate_mask_coverage(foreground_mask1) * 100.0,
                calculate_mask_coverage(foreground_mask2) * 100.0,
            )
        )

        result = build_success_result(
            similarity=similarity,
            orb_score=orb_score,
            histogram_score=histogram_score,
            shape_score=shape_score,
            verified_matches=verified_matches,
            keypoints_image1=len(keypoints1),
            keypoints_image2=len(keypoints2),
        )
        print(json.dumps(result))
    except Exception as exc:  # pragma: no cover - runtime safeguard
        log_debug(f"compare.py error: {exc}")
        print(json.dumps(build_error_result(str(exc))))


if __name__ == "__main__":
    main()
