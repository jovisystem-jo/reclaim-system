#!/usr/bin/env python3
"""
OpenCV Image Similarity Comparison using ORB (Oriented FAST and Rotated BRIEF)
Installation: pip install opencv-python opencv-contrib-python numpy
"""

import sys
import cv2
import numpy as np
import os
import json

def log_debug(message):
    """Send debug messages to stderr (won't interfere with JSON output)"""
    print(message, file=sys.stderr)

def load_and_preprocess_image(image_path):
    """Load image and convert to grayscale"""
    if not os.path.exists(image_path):
        log_debug(f"File not found: {image_path}")
        return None, None, None
    
    img = cv2.imread(image_path)
    if img is None:
        log_debug(f"Failed to load image: {image_path}")
        return None, None, None
    
    original = img.copy()
    # Convert to grayscale
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    
    # Apply histogram equalization for better feature matching
    gray = cv2.equalizeHist(gray)
    
    # Resize if too large (max 800px)
    height, width = gray.shape
    max_size = 800
    if height > max_size or width > max_size:
        scale = max_size / max(height, width)
        new_width = int(width * scale)
        new_height = int(height * scale)
        gray = cv2.resize(gray, (new_width, new_height))
        original = cv2.resize(original, (new_width, new_height))
    
    return gray, original, img.shape

def extract_features(image, max_features=1000):
    """Extract ORB features from image"""
    orb = cv2.ORB_create(nfeatures=max_features, scaleFactor=1.2, nlevels=8, edgeThreshold=31, firstLevel=0, WTA_K=2, patchSize=31)
    keypoints, descriptors = orb.detectAndCompute(image, None)
    
    if descriptors is None:
        return keypoints, None
    
    return keypoints, descriptors

def calculate_similarity(desc1, desc2):
    """Calculate similarity between two feature descriptors"""
    if desc1 is None or desc2 is None or len(desc1) == 0 or len(desc2) == 0:
        return 0.0
    
    # Try FLANN based matcher for better results
    FLANN_INDEX_LSH = 6
    index_params = dict(algorithm=FLANN_INDEX_LSH, table_number=12, key_size=20, multi_probe_level=2)
    search_params = dict(checks=50)
    
    try:
        flann = cv2.FlannBasedMatcher(index_params, search_params)
        matches = flann.knnMatch(desc1, desc2, k=2)
    except:
        # Fallback to BFMatcher
        bf = cv2.BFMatcher(cv2.NORM_HAMMING, crossCheck=False)
        matches = bf.knnMatch(desc1, desc2, k=2)
    
    # Apply Lowe's ratio test
    good_matches = []
    for match_pair in matches:
        if len(match_pair) == 2:
            m, n = match_pair
            if m.distance < 0.7 * n.distance:  # Stricter threshold
                good_matches.append(m)
    
    if len(good_matches) == 0:
        return 0.0
    
    # Calculate similarity percentage
    max_possible_matches = min(len(desc1), len(desc2))
    similarity = (len(good_matches) / max_possible_matches) * 100
    
    # Normalize and boost
    if similarity > 20:
        similarity = min(100, similarity + 20)
    
    return min(100.0, similarity)

def get_histogram_similarity(img1, img2):
    """Calculate histogram similarity as additional metric"""
    # Convert to HSV for better color comparison
    hsv1 = cv2.cvtColor(img1, cv2.COLOR_BGR2HSV)
    hsv2 = cv2.cvtColor(img2, cv2.COLOR_BGR2HSV)
    
    # Calculate histogram
    hist1 = cv2.calcHist([hsv1], [0, 1], None, [180, 256], [0, 180, 0, 256])
    hist2 = cv2.calcHist([hsv2], [0, 1], None, [180, 256], [0, 180, 0, 256])
    
    # Normalize
    cv2.normalize(hist1, hist1, 0, 1, cv2.NORM_MINMAX)
    cv2.normalize(hist2, hist2, 0, 1, cv2.NORM_MINMAX)
    
    # Compare histograms
    similarity = cv2.compareHist(hist1, hist2, cv2.HISTCMP_CORREL)
    
    # Convert to percentage
    return max(0, min(100, similarity * 100))

def main():
    if len(sys.argv) < 3:
        result = {"error": "Missing arguments", "similarity": 0}
        print(json.dumps(result))
        return
    
    img1_path = sys.argv[1]
    img2_path = sys.argv[2]
    
    # Debug info - sent to stderr
    log_debug(f"Comparing: {img1_path} vs {img2_path}")
    
    # Load and preprocess images
    img1_gray, img1_color, shape1 = load_and_preprocess_image(img1_path)
    img2_gray, img2_color, shape2 = load_and_preprocess_image(img2_path)
    
    if img1_gray is None or img2_gray is None:
        result = {"error": "Failed to load images", "similarity": 0}
        print(json.dumps(result))
        return
    
    # Extract features
    kp1, desc1 = extract_features(img1_gray)
    kp2, desc2 = extract_features(img2_gray)
    
    if desc1 is None or desc2 is None:
        result = {"error": "No features detected", "similarity": 0}
        print(json.dumps(result))
        return
    
    # Calculate ORB similarity
    orb_similarity = calculate_similarity(desc1, desc2)
    log_debug(f"ORB similarity: {orb_similarity}")
    
    # Calculate histogram similarity for color matching
    hist_similarity = 0
    if img1_color is not None and img2_color is not None:
        hist_similarity = get_histogram_similarity(img1_color, img2_color)
        log_debug(f"Histogram similarity: {hist_similarity}")
    
    # Combine both metrics (60% ORB, 40% Histogram for better accuracy)
    final_similarity = (orb_similarity * 0.6) + (hist_similarity * 0.4)
    
    # If images are identical paths, return 100%
    if os.path.abspath(img1_path) == os.path.abspath(img2_path):
        final_similarity = 100.0
    
    result = {
        "similarity": round(final_similarity, 2),
        "orb_score": round(orb_similarity, 2),
        "hist_score": round(hist_similarity, 2),
        "features1": len(kp1) if kp1 else 0,
        "features2": len(kp2) if kp2 else 0
    }
    
    log_debug(f"Final similarity: {final_similarity}")
    
    # Output only JSON to stdout
    print(json.dumps(result))

if __name__ == "__main__":
    main()