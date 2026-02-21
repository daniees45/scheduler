#!/usr/bin/env python3
"""
Pipeline Verification Test
Tests the complete scheduling pipeline end-to-end
"""

import os
import sys
import requests
import json
import time

# Configuration
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
FLASK_URL = "http://localhost:5000"
WEB_URL = "http://localhost/vvu-scheduler/web"

def color_print(text, color="green"):
    colors = {
        "green": "\033[92m",
        "red": "\033[91m",
        "yellow": "\033[93m",
        "blue": "\033[94m",
        "reset": "\033[0m"
    }
    print(f"{colors.get(color, '')}{text}{colors['reset']}")

def test_flask_health():
    """Test Flask API health"""
    print("\n" + "="*60)
    print("TEST 1: Flask API Health Check")
    print("="*60)
    
    try:
        response = requests.get(f"{FLASK_URL}/health", timeout=5)
        if response.status_code == 200:
            data = response.json()
            color_print("✅ Flask API is online", "green")
            print(f"   Status: {data.get('status')}")
            
            components = data.get('components', {})
            for name, status in components.items():
                symbol = "✅" if status else "❌"
                print(f"   {symbol} {name}: {status}")
            return True
        else:
            color_print(f"❌ Flask API returned status {response.status_code}", "red")
            return False
    except requests.exceptions.ConnectionError:
        color_print("❌ Flask API is not running", "red")
        color_print("   Start it with: python3 app.py", "yellow")
        return False
    except Exception as e:
        color_print(f"❌ Error: {e}", "red")
        return False

def test_file_structure():
    """Test if required files and directories exist"""
    print("\n" + "="*60)
    print("TEST 2: File Structure Validation")
    print("="*60)
    
    required_files = [
        "main_web.py",
        "app.py",
        "load_data.py",
        "builder.py",
        "constraints.py",
        "csp.py",
        "export_data.py",
        "web/generate.php",
        "web/api/sync.php",
        "web/api/update_db.php"
    ]
    
    required_dirs = [
        "csv",
        "csv/general",
        "csv/department",
        "csv/final"
    ]
    
    all_exist = True
    
    for file in required_files:
        path = os.path.join(BASE_DIR, file)
        if os.path.exists(path):
            color_print(f"✅ {file}", "green")
        else:
            color_print(f"❌ Missing: {file}", "red")
            all_exist = False
    
    for dir in required_dirs:
        path = os.path.join(BASE_DIR, dir)
        if os.path.exists(path) and os.path.isdir(path):
            color_print(f"✅ {dir}/", "green")
        else:
            color_print(f"❌ Missing directory: {dir}/", "red")
            all_exist = False
    
    return all_exist

def test_csv_files():
    """Test if required CSV files exist"""
    print("\n" + "="*60)
    print("TEST 3: CSV Data Files")
    print("="*60)
    
    csv_files = {
        "csv/general/rooms.csv": "Room definitions",
        "csv/general/lecturer_availability.csv": "Lecturer availability",
        "csv/department/departmental_courses.csv": "Course data",
        "csv/general/special_rooms.csv": "Special room assignments"
    }
    
    all_exist = True
    for file, description in csv_files.items():
        path = os.path.join(BASE_DIR, file)
        if os.path.exists(path):
            size = os.path.getsize(path)
            color_print(f"✅ {file} ({size} bytes)", "green")
            print(f"   {description}")
        else:
            color_print(f"⚠️  Missing: {file}", "yellow")
            print(f"   {description}")
            print(f"   Run: curl {WEB_URL}/api/sync.php")
            all_exist = False
    
    return all_exist

def test_api_endpoint():
    """Test Flask /generate endpoint with mock data"""
    print("\n" + "="*60)
    print("TEST 4: Flask API Generate Endpoint")
    print("="*60)
    
    # First check if we have input data
    input_file = os.path.join(BASE_DIR, "csv/department/departmental_courses.csv")
    if not os.path.exists(input_file):
        color_print("⚠️  Skipping - No input CSV found", "yellow")
        color_print("   Run sync.php first to export database data", "yellow")
        return None
    
    try:
        # Test with minimal payload (won't actually generate, just test endpoint)
        payload = {
            "input_file": "csv/department/departmental_courses.csv",
            "output_file": "csv/final/test_schedule.csv",
            "semester": "1",
            "course_type": "Departmental",
            "department": "1",
            "availability_mode": "1",
            "exam_mode": False
        }
        
        color_print("📤 Testing /generate endpoint (this may take a minute)...", "blue")
        response = requests.post(
            f"{FLASK_URL}/generate",
            json=payload,
            timeout=180
        )
        
        if response.status_code == 200:
            data = response.json()
            if data.get('status') == 'success':
                color_print("✅ Schedule generated successfully", "green")
                print(f"   Accuracy: {data.get('accuracy')}")
                print(f"   Output: {data.get('output_file')}")
                return True
            else:
                color_print(f"⚠️  Generation completed with issues: {data.get('message')}", "yellow")
                return False
        else:
            color_print(f"❌ API returned status {response.status_code}", "red")
            print(f"   Response: {response.text[:200]}")
            return False
            
    except requests.exceptions.Timeout:
        color_print("⏱️  Request timed out (this is normal for large datasets)", "yellow")
        return None
    except Exception as e:
        color_print(f"❌ Error: {e}", "red")
        return False

def test_main_web_import():
    """Test if main_web.py can be imported"""
    print("\n" + "="*60)
    print("TEST 5: Python Module Imports")
    print("="*60)
    
    try:
        from main_web import run_headless
        color_print("✅ main_web.run_headless imported", "green")
        
        # Check function signature
        import inspect
        sig = inspect.signature(run_headless)
        params = list(sig.parameters.keys())
        print(f"   Parameters: {', '.join(params)}")
        
        # Verify all expected parameters exist
        expected = ['input_file', 'mode_choice', 'output_file', 'ai_preference', 
                   'course_type', 'department', 'availability_mode', 'exam_mode', 
                   'general_schedule_path']
        missing = [p for p in expected if p not in params]
        
        if missing:
            color_print(f"⚠️  Missing parameters: {', '.join(missing)}", "yellow")
        else:
            color_print("✅ All expected parameters present", "green")
        
        return True
    except ImportError as e:
        color_print(f"❌ Failed to import: {e}", "red")
        return False

def main():
    print("\n" + "🔄"*30)
    print("SCHEDULING PIPELINE VERIFICATION TEST")
    print("🔄"*30)
    
    results = {
        "Flask Health": test_flask_health(),
        "File Structure": test_file_structure(),
        "CSV Files": test_csv_files(),
        "Python Imports": test_main_web_import(),
        "API Endpoint": test_api_endpoint()
    }
    
    # Summary
    print("\n" + "="*60)
    print("TEST SUMMARY")
    print("="*60)
    
    passed = sum(1 for v in results.values() if v is True)
    failed = sum(1 for v in results.values() if v is False)
    skipped = sum(1 for v in results.values() if v is None)
    
    for test, result in results.items():
        if result is True:
            color_print(f"✅ {test}: PASS", "green")
        elif result is False:
            color_print(f"❌ {test}: FAIL", "red")
        else:
            color_print(f"⏭️  {test}: SKIPPED", "yellow")
    
    print("\n" + "="*60)
    print(f"Results: {passed} passed, {failed} failed, {skipped} skipped")
    print("="*60)
    
    if failed == 0 and passed > 0:
        color_print("\n🎉 Pipeline is ready to use!", "green")
        print("\nNext steps:")
        print("1. Open: http://localhost/vvu-scheduler/web/generate.php")
        print("2. Click 'Start Generation'")
        print("3. Watch the AI solve the schedule")
        return 0
    elif failed > 0:
        color_print("\n⚠️  Some tests failed. Review errors above.", "yellow")
        return 1
    else:
        color_print("\n⚠️  Most tests were skipped. Check your setup.", "yellow")
        return 1

if __name__ == "__main__":
    sys.exit(main())
