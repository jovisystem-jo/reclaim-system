#!/usr/bin/env python3
"""
Production image comparator for RECLAIM.

Scoring model:
- ORB keypoint matching with Lowe ratio test
- RANSAC homography verification
- HSV histogram + dominant color comparison
- Final score = (orb_score * 0.35) + (histogram_score * 0.45) + (shape_score * 0.20)
"""

import json
import os
import sys
from typing import Dict, List, Tuple

import cv2
import numpy as np

MAX_DIMENSION = 1200
LOWE_RATIO = 0.75
RANSAC_REPROJECTION_THRESHOLD = 5.0
ORB_WEIGHT = 0.35
COLOR_WEIGHT = 0.45
SHAPE_WEIGHT = 0.20


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


def extract_center_region(image: np.ndarray, border_ratio: float = 0.1) -> np.ndarray:
    """Crop a centered region to reduce background bias in product-style photos."""
    height, width = image.shape[:2]
    top = int(height * border_ratio)
    bottom = int(height * (1.0 - border_ratio))
    left = int(width * border_ratio)
    right = int(width * (1.0 - border_ratio))

    if bottom <= top or right <= left:
        return image

    return image[top:bottom, left:right]


def load_image(image_path: str) -> Tuple[np.ndarray, np.ndarray]:
    """Load an image in color and grayscale forms."""
    if not os.path.isfile(image_path):
        raise FileNotFoundError(f"Image not found: {image_path}")

    color_image = cv2.imread(image_path, cv2.IMREAD_COLOR)
    if color_image is None:
        raise ValueError(f"Unable to load image: {image_path}")

    color_image = resize_if_needed(color_image)
    grayscale_image = cv2.cvtColor(color_image, cv2.COLOR_BGR2GRAY)
    grayscale_image = cv2.equalizeHist(grayscale_image)

    return color_image, grayscale_image


def create_orb() -> cv2.ORB:
    """Create the requested ORB detector configuration."""
    return cv2.ORB_create(
        nfeatures=3000,
        scaleFactor=1.2,
        nlevels=8,
        edgeThreshold=15,
        fastThreshold=10,
    )


def extract_features(grayscale_image: np.ndarray) -> Tuple[List[cv2.KeyPoint], np.ndarray]:
    """Extract ORB keypoints and descriptors."""
    orb = create_orb()
    keypoints, descriptors = orb.detectAndCompute(grayscale_image, None)
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

    return good_matches


def verify_matches_with_homography(
    keypoints1: List[cv2.KeyPoint],
    keypoints2: List[cv2.KeyPoint],
    good_matches: List[cv2.DMatch],
) -> int:
    """Count geometrically verified matches using RANSAC homography."""
    if len(good_matches) < 4:
        return 0

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
        return 0

    return int(np.sum(inlier_mask))


def calculate_orb_score(
    keypoints1: List[cv2.KeyPoint],
    descriptors1: np.ndarray,
    keypoints2: List[cv2.KeyPoint],
    descriptors2: np.ndarray,
) -> Tuple[float, int]:
    """Compute the ORB score from verified inlier matches."""
    good_matches = get_good_matches(descriptors1, descriptors2)
    total_good_matches = len(good_matches)

    if total_good_matches == 0:
        return 0.0, 0

    verified_matches = verify_matches_with_homography(keypoints1, keypoints2, good_matches)
    orb_score = (verified_matches / float(total_good_matches)) * 100.0

    return clamp_score(orb_score), verified_matches


def calculate_histogram_score(image1: np.ndarray, image2: np.ndarray) -> float:
    """Compare images using HSV histograms plus dominant-color distance."""
    region1 = extract_center_region(image1)
    region2 = extract_center_region(image2)

    hsv_image1 = cv2.cvtColor(region1, cv2.COLOR_BGR2HSV)
    hsv_image2 = cv2.cvtColor(region2, cv2.COLOR_BGR2HSV)

    histogram1 = cv2.calcHist(
        [hsv_image1],
        [0, 1, 2],
        None,
        [16, 16, 16],
        [0, 180, 0, 256, 0, 256],
    )
    histogram2 = cv2.calcHist(
        [hsv_image2],
        [0, 1, 2],
        None,
        [16, 16, 16],
        [0, 180, 0, 256, 0, 256],
    )

    cv2.normalize(histogram1, histogram1, 0, 1, cv2.NORM_MINMAX)
    cv2.normalize(histogram2, histogram2, 0, 1, cv2.NORM_MINMAX)

    distance = cv2.compareHist(histogram1, histogram2, cv2.HISTCMP_BHATTACHARYYA)
    histogram_similarity = clamp_score((1.0 - distance) * 100.0)

    # Reinforce dominant-color agreement so black/white or black/pink mismatches
    # do not survive on shape similarity alone.
    lab_image1 = cv2.cvtColor(region1, cv2.COLOR_BGR2LAB)
    lab_image2 = cv2.cvtColor(region2, cv2.COLOR_BGR2LAB)
    mean_color1 = lab_image1.reshape(-1, 3).mean(axis=0)
    mean_color2 = lab_image2.reshape(-1, 3).mean(axis=0)
    color_distance = float(np.linalg.norm(mean_color1 - mean_color2))
    max_distance = float(np.sqrt(3 * (255.0 ** 2)))
    mean_color_similarity = clamp_score((1.0 - min(color_distance / max_distance, 1.0)) * 100.0)

    similarity = (histogram_similarity * 0.65) + (mean_color_similarity * 0.35)
    return clamp_score(similarity)



def calculate_shape_score(image1: np.ndarray, image2: np.ndarray) -> float:
    """Compare the largest object contour shape from two images."""
    def largest_contour(image: np.ndarray):
        region = extract_center_region(image)
        gray = cv2.cvtColor(region, cv2.COLOR_BGR2GRAY)
        gray = cv2.GaussianBlur(gray, (5, 5), 0)

        # Otsu threshold handles light/dark backgrounds better than a fixed threshold.
        _, threshold = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

        contours, _ = cv2.findContours(threshold, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if not contours:
            # Try inverted threshold when the object/background polarity is opposite.
            threshold = cv2.bitwise_not(threshold)
            contours, _ = cv2.findContours(threshold, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        if not contours:
            return None

        image_area = float(region.shape[0] * region.shape[1])
        valid = [c for c in contours if cv2.contourArea(c) >= image_area * 0.01]
        if not valid:
            valid = contours

        return max(valid, key=cv2.contourArea)

    contour1 = largest_contour(image1)
    contour2 = largest_contour(image2)

    if contour1 is None or contour2 is None:
        return 0.0

    distance = cv2.matchShapes(contour1, contour2, cv2.CONTOURS_MATCH_I1, 0.0)
    similarity = (1.0 / (1.0 + float(distance))) * 100.0
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

    image_path_1 = sys.argv[1]
    image_path_2 = sys.argv[2]

    try:
        log_debug(f"Comparing images: {image_path_1} <> {image_path_2}")

        color_image1, grayscale_image1 = load_image(image_path_1)
        color_image2, grayscale_image2 = load_image(image_path_2)

        keypoints1, descriptors1 = extract_features(grayscale_image1)
        keypoints2, descriptors2 = extract_features(grayscale_image2)

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
            keypoints2,
            descriptors2,
        )
        histogram_score = calculate_histogram_score(color_image1, color_image2)
        shape_score = calculate_shape_score(color_image1, color_image2)
        similarity = (orb_score * ORB_WEIGHT) + (histogram_score * COLOR_WEIGHT) + (shape_score * SHAPE_WEIGHT)

        log_debug(
            "ORB={:.2f}, HIST={:.2f}, SHAPE={:.2f}, VERIFIED={}, KP1={}, KP2={}".format(
                orb_score,
                histogram_score,
                shape_score,
                verified_matches,
                len(keypoints1),
                len(keypoints2),
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
