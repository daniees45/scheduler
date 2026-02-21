from flask import Flask, request, jsonify
from flask_cors import CORS
import os
import sys
from main_web import run_headless
from exam_main_web import run_headless_exam

app = Flask(__name__)
CORS(app) # Enable cross-origin for potential browser-direct calls

@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        "status": "online",
        "engine": "CSP-Solver",
        "python_version": sys.version
    })

@app.route('/solve', methods=['POST'])
def solve():
    data = request.json
    input_file = data.get('input_file', 'departmental_courses.csv')
    output_file = data.get('output_file', 'as_results.csv')
    mode = data.get('mode', 2) # Default to headless
    
    print(f"[API] Received solve request for {input_file} -> {output_file}")
    
    try:
        # We rely on PHP to have synced the DB to CSV before this call
        if not os.path.exists(input_file):
            return jsonify({
                "success": False,
                "message": f"Input file {input_file} not found. Sync failed?"
            }), 400
            
        success = run_headless(input_file, mode, output_file, 1)
        
        if success:
            return jsonify({
                "success": True,
                "message": "Optimization complete.",
                "download_url": f"../{output_file}" # Relative path for PHP/Web
            })
        else:
            return jsonify({
                "success": False,
                "message": "AI failed to find a valid solution under current constraints."
            })
            
    except Exception as e:
        return jsonify({
            "success": False,
            "message": f"Internal AI Error: {str(e)}"
        }), 500


@app.route('/solve_exam', methods=['POST'])
def solve_exam():
    data = request.json
    input_file = data.get('input_file', 'exam_courses.csv')
    output_file = data.get('output_file', 'exam_results.csv')

    print(f"[API] Received exam solve request for {input_file} -> {output_file}")

    try:
        if not os.path.exists(input_file):
            return jsonify({
                "success": False,
                "message": f"Input file {input_file} not found."
            }), 400

        success = run_headless_exam(input_file, output_file)

        if success:
            return jsonify({
                "success": True,
                "message": "Exam optimization complete.",
                "download_url": f"../{output_file}"
            })
        else:
            return jsonify({
                "success": False,
                "message": "AI failed to find a valid exam solution under current constraints."
            })

    except Exception as e:
        return jsonify({
            "success": False,
            "message": f"Internal AI Error: {str(e)}"
        }), 500

if __name__ == '__main__':
    # Running on default port 5000
    print("--- VVU AI SCHEDULER SERVICE STARTING ---")
    app.run(host='0.0.0.0', port=5000)
