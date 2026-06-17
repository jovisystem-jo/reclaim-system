"""
Lightweight Flask wrapper around compare.py.
Deploy this on Render, Railway, or any free Python host.
"""
import os
import sys
import json
import tempfile
import requests
from flask import Flask, request, jsonify

sys.path.insert(0, os.path.dirname(__file__))

from api.compare import (
    load_image,
    extract_features,
    calculate_orb_score,
    calculate_histogram_score,
    calculate_shape_score,
    apply_similarity_adjustments,
    build_success_result,
    build_error_result,
    ORB_WEIGHT,
    COLOR_WEIGHT,
    SHAPE_WEIGHT,
    IMPORT_ERROR,
)

app = Flask(__name__)

API_KEY = os.environ.get('OPENCV_SERVICE_API_KEY', '')


def check_api_key():
    if API_KEY == '':
        return True
    return request.headers.get('X-API-Key') == API_KEY


@app.route('/health', methods=['GET'])
def health():
    if IMPORT_ERROR is not None:
        return jsonify({'status': 'error', 'detail': str(IMPORT_ERROR)}), 500
    return jsonify({'status': 'ok'})


@app.route('/compare', methods=['POST'])
def compare():
    if not check_api_key():
        return jsonify({'error': 'Unauthorized'}), 401

    if IMPORT_ERROR is not None:
        return jsonify(build_error_result(f'OpenCV unavailable: {IMPORT_ERROR}')), 500

    image1_url = request.form.get('image1_url', '')
    image2_url = request.form.get('image2_url', '')

    if not image1_url or not image2_url:
        return jsonify(build_error_result('image1_url and image2_url are required')), 400

    tmp1 = tmp2 = None
    try:
        tmp1 = _download_image(image1_url)
        tmp2 = _download_image(image2_url)

        if tmp1 is None or tmp2 is None:
            return jsonify(build_error_result('Failed to download one or both images')), 400

        color1, gray1, mask1 = load_image(tmp1)
        color2, gray2, mask2 = load_image(tmp2)

        kp1, desc1 = extract_features(gray1, mask1)
        kp2, desc2 = extract_features(gray2, mask2)

        orb_score, verified = calculate_orb_score(kp1, desc1, gray1.shape, kp2, desc2, gray2.shape)
        hist_score = calculate_histogram_score(color1, color2, mask1, mask2)
        shape_score = calculate_shape_score(mask1, mask2)

        base = (orb_score * ORB_WEIGHT) + (hist_score * COLOR_WEIGHT) + (shape_score * SHAPE_WEIGHT)
        similarity = apply_similarity_adjustments(base, orb_score, hist_score, shape_score, verified)

        result = build_success_result(similarity, orb_score, hist_score, shape_score, verified, len(kp1), len(kp2))
        return jsonify(result)

    except Exception as exc:
        return jsonify(build_error_result(str(exc))), 500
    finally:
        for tmp in [tmp1, tmp2]:
            if tmp and os.path.isfile(tmp):
                try:
                    os.unlink(tmp)
                except OSError:
                    pass


def _download_image(url: str):
    try:
        response = requests.get(url, timeout=10, stream=True)
        response.raise_for_status()
        suffix = '.jpg'
        if 'png' in response.headers.get('Content-Type', ''):
            suffix = '.png'
        with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
            for chunk in response.iter_content(8192):
                tmp.write(chunk)
            return tmp.name
    except Exception:
        return None


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port)
