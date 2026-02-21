#!/usr/bin/env python3
"""
AI Features Integration Test Suite
Tests all new AI endpoints in the enhanced app.py
"""

import requests
import json
import sys
from datetime import datetime

# Configuration
BASE_URL = "http://localhost:5000"
TEST_RESULTS = []

def test_endpoint(name, method, endpoint, data=None, expected_status=200):
    """Test a single endpoint"""
    try:
        url = f"{BASE_URL}{endpoint}"
        print(f"\n{'='*60}")
        print(f"TEST: {name}")
        print(f"{'='*60}")
        print(f"URL: {method} {url}")
        
        if method == "GET":
            response = requests.get(url, timeout=10)
        elif method == "POST":
            print(f"Body: {json.dumps(data, indent=2)}")
            response = requests.post(url, json=data, timeout=10)
        
        status_ok = response.status_code == expected_status
        status_color = "✅" if status_ok else "❌"
        
        print(f"\nStatus: {status_color} {response.status_code}")
        
        try:
            response_json = response.json()
            print(f"Response: {json.dumps(response_json, indent=2)}")
            
            result = {
                "test": name,
                "status": "PASS" if status_ok else "FAIL",
                "endpoint": endpoint,
                "method": method,
                "response_status": response.status_code,
                "expected_status": expected_status
            }
        except:
            print(f"Response: {response.text[:200]}")
            result = {
                "test": name,
                "status": "FAIL" if not status_ok else "PASS",
                "endpoint": endpoint,
                "method": method,
                "response_status": response.status_code,
                "error": "Could not parse JSON"
            }
        
        TEST_RESULTS.append(result)
        return status_ok
        
    except requests.exceptions.ConnectionError:
        print(f"❌ Connection Error - Is Flask running on {BASE_URL}?")
        TEST_RESULTS.append({
            "test": name,
            "status": "ERROR",
            "endpoint": endpoint,
            "error": "Connection refused"
        })
        return False
    except Exception as e:
        print(f"❌ Exception: {e}")
        TEST_RESULTS.append({
            "test": name,
            "status": "ERROR",
            "endpoint": endpoint,
            "error": str(e)
        })
        return False

def main():
    print("\n" + "="*60)
    print("VVU AI SCHEDULER - ENDPOINT INTEGRATION TEST SUITE")
    print("="*60)
    print(f"Base URL: {BASE_URL}")
    print(f"Time: {datetime.now().isoformat()}\n")
    
    # Test 1: Health Check
    test_endpoint(
        "Health Check",
        "GET",
        "/health",
        expected_status=200
    )
    
    # Test 2: AI Status
    test_endpoint(
        "AI System Status",
        "GET",
        "/ai/status",
        expected_status=200
    )
    
    # Test 3: Progress (Idle)
    test_endpoint(
        "Progress Tracking",
        "GET",
        "/progress",
        expected_status=200
    )
    
    # Test 4: Quality Prediction
    test_endpoint(
        "Quality Prediction",
        "POST",
        "/predict/quality",
        data={
            "num_events": 45,
            "total_hours": 180.0,
            "avg_gap_between": 2.5,
            "morning_load": 0.35,
            "afternoon_load": 0.42,
            "evening_load": 0.23,
            "num_conflicts": 0,
            "avg_event_duration": 1.5,
            "q_learner_accept_rate": 0.88
        },
        expected_status=200
    )
    
    # Test 5: Feasibility Prediction
    test_endpoint(
        "Feasibility Prediction",
        "POST",
        "/predict/feasibility",
        data={
            "course_code": "CSC301",
            "day": "MON",
            "slot": 10,
            "room_type": "general",
            "enrollment": 50
        },
        expected_status=200
    )
    
    # Test 6: Feedback Recording
    test_endpoint(
        "Feedback Recording",
        "POST",
        "/feedback",
        data={
            "action": "accept",
            "quality": 0.85,
            "metadata": {
                "department": "1",
                "timestamp": datetime.now().isoformat()
            }
        },
        expected_status=200
    )
    
    # Test 7: Suggestions Generation
    test_endpoint(
        "Suggestions Generation",
        "POST",
        "/suggestions",
        data={
            "input_file": "departmental_courses.csv"
        },
        expected_status=200
    )
    
    # Test 8: Diagnostics
    test_endpoint(
        "Schedule Diagnostics",
        "POST",
        "/diagnostics",
        data={
            "input_file": "final_web_schedule.csv"
        },
        expected_status=200
    )
    
    # Test 9: Performance Analytics
    test_endpoint(
        "Performance Analytics",
        "GET",
        "/analytics/performance",
        expected_status=200
    )
    
    # Test 10: Schedule Explainability
    test_endpoint(
        "AI Explainability",
        "POST",
        "/explain/schedule",
        data={
            "schedule_id": "final_web_schedule.csv"
        },
        expected_status=200
    )
    
    # Test 11: Invalid Endpoint (404 Test)
    test_endpoint(
        "Invalid Endpoint (404 Test)",
        "GET",
        "/invalid/endpoint",
        expected_status=404
    )
    
    # Summary Report
    print("\n" + "="*60)
    print("TEST SUMMARY")
    print("="*60)
    
    passed = sum(1 for r in TEST_RESULTS if r["status"] == "PASS")
    failed = sum(1 for r in TEST_RESULTS if r["status"] == "FAIL")
    errors = sum(1 for r in TEST_RESULTS if r["status"] == "ERROR")
    total = len(TEST_RESULTS)
    
    print(f"\nResults:")
    print(f"  ✅ Passed: {passed}/{total}")
    print(f"  ❌ Failed: {failed}/{total}")
    print(f"  ⚠️  Errors: {errors}/{total}")
    print(f"  Success Rate: {(passed/total)*100:.1f}%")
    
    print(f"\nDetailed Results:")
    for result in TEST_RESULTS:
        status_icon = "✅" if result["status"] == "PASS" else "❌" if result["status"] == "FAIL" else "⚠️"
        print(f"  {status_icon} {result['test']}: {result['status']}")
        if "error" in result:
            print(f"     Error: {result['error']}")
    
    # Save results to file
    with open("ai_integration_test_results.json", "w") as f:
        json.dump({
            "timestamp": datetime.now().isoformat(),
            "base_url": BASE_URL,
            "summary": {
                "passed": passed,
                "failed": failed,
                "errors": errors,
                "total": total,
                "success_rate": (passed/total)*100
            },
            "results": TEST_RESULTS
        }, f, indent=2)
    
    print(f"\nResults saved to: ai_integration_test_results.json")
    
    # Exit code
    return 0 if (failed == 0 and errors == 0) else 1

if __name__ == "__main__":
    sys.exit(main())
