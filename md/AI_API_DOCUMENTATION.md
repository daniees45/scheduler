# VVU AI Scheduler - API Documentation

## Base URL
```
http://localhost:5000
https://my-ai-service-yj44.onrender.com  (Production)
```

---

## Health & Status Endpoints

### GET /health
Check if AI engine is operational and all components are available.

**Response:**
```json
{
  "status": "ok",
  "message": "VVU Scheduler API Ready",
  "timestamp": "2026-02-14T10:30:00.000Z",
  "components": {
    "scheduler": true,
    "deep_learning": true,
    "q_learner": true,
    "feasibility_classifier": true,
    "diagnostics": true,
    "bidirectional_feedback": true
  }
}
```

### GET /ai/status
Get detailed AI system status with component readiness.

**Response:**
```json
{
  "scheduler_available": true,
  "deep_learning_available": true,
  "q_learner_available": true,
  "feasibility_available": true,
  "feedback_available": true,
  "classifier_accuracy": 0.876,
  "feedback_state": {...}
}
```

### GET /progress
Get current scheduling progress for real-time polling.

**Response:**
```json
{
  "job_id": "schedule_1707902945",
  "status": "running|success|failed|error",
  "percent": 65,
  "placed": 45,
  "message": "Placing courses...",
  "timestamp": "2026-02-14T10:30:00.000Z"
}
```

---

## Schedule Generation Endpoints

### POST /generate
Generate an optimized class schedule using AI.

**Request Body:**
```json
{
  "job_id": "schedule_12345",
  "input_file": "departmental_courses.csv",
  "output_file": "final_web_schedule.csv",
  "course_type": "Departmental",
  "department": "1",
  "availability_mode": "1",
  "exam_mode": false,
  "semester": 1
}
```

**Parameters:**
- `input_file`: CSV file with course data (security: no path traversal)
- `output_file`: Output CSV filename
- `course_type`: "Departmental" or "General"
- `department`: Department ID (1=CS, 2=Nursing, 3=Theology, 4=General)
- `availability_mode`: 1=Auto-Expand, 2=Strict
- `exam_mode`: false for class schedule, true for exam schedule
- `semester`: 1 or 2

**Response:**
```json
{
  "status": "success|error",
  "message": "Schedule generated successfully",
  "accuracy": "87.50%",
  "output_file": "final_web_schedule.csv",
  "job_id": "schedule_12345"
}
```

### POST /generate/exam
Generate an optimized exam schedule.

**Request Body:**
```json
{
  "job_id": "exam_12345",
  "input_file": "exam_courses.csv",
  "output_file": "exam_schedule.csv"
}
```

**Response:**
```json
{
  "status": "success|error",
  "message": "Exam schedule generated successfully",
  "output_file": "exam_schedule.csv",
  "job_id": "exam_12345"
}
```

---

## AI Quality & Prediction Endpoints

### POST /predict/quality
Predict the overall quality of a schedule using deep learning classifier.

**Request Body:**
```json
{
  "num_events": 45,
  "total_hours": 180.0,
  "avg_gap_between": 2.5,
  "morning_load": 0.35,
  "afternoon_load": 0.42,
  "evening_load": 0.23,
  "num_conflicts": 0,
  "avg_event_duration": 1.5,
  "q_learner_accept_rate": 0.88
}
```

**Response:**
```json
{
  "status": "success",
  "quality": {
    "overall_score": 8.2,
    "category": "Good",
    "completion_probability": 0.92,
    "conflict_severity": 0.05,
    "time_distribution_score": 0.87,
    "confidence": 0.89
  }
}
```

**Categories:** Poor (<4), Fair (4-6), Good (6-8), Excellent (8-10)

### POST /predict/feasibility
Predict if a specific scheduling assignment will succeed.

**Request Body:**
```json
{
  "course_code": "CSC301",
  "day": "MON",
  "slot": 10,
  "room_type": "general",
  "enrollment": 50
}
```

**Response:**
```json
{
  "status": "success",
  "probability": 0.92,
  "feasible": true,
  "confidence": 0.84
}
```

---

## Feedback & Learning Endpoints

### POST /feedback
Record user feedback for bidirectional learning system.

**Request Body:**
```json
{
  "action": "accept|reject|modify",
  "quality": 0.82,
  "features": {
    "num_events": 45,
    "total_hours": 180.0,
    "morning_load": 0.35
  },
  "metadata": {
    "department": "1",
    "timestamp": "2026-02-14T10:30:00.000Z",
    "user_id": "lecturer_001"
  }
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Feedback recorded: accept",
  "timestamp": "2026-02-14T10:30:00.000Z"
}
```

**How It Works:**
- Feedback is recorded in bidirectional system
- Q-Learner learns user preferences
- Deep learning neural network updates
- All systems synchronized for continuous improvement

### POST /suggestions
Generate schedule improvement suggestions based on current data.

**Request Body:**
```json
{
  "input_file": "departmental_courses.csv"
}
```

**Response:**
```json
{
  "status": "success",
  "suggestions": [
    {
      "id": "sugg_001",
      "type": "optimization",
      "title": "Balance Morning Load",
      "description": "Consider redistributing morning courses to afternoon slots",
      "priority": "medium",
      "impact": "Reduces student fatigue"
    },
    {
      "id": "sugg_002",
      "type": "efficiency",
      "title": "Consolidate Venues",
      "description": "Move related courses to clustering nearby rooms",
      "priority": "low",
      "impact": "Reduces lecturer travel time"
    }
  ],
  "count": 2
}
```

---

## Diagnostics & Analytics Endpoints

### POST /diagnostics
Run detailed analysis on a schedule to identify issues and optimization opportunities.

**Request Body:**
```json
{
  "input_file": "final_web_schedule.csv"
}
```

**Response:**
```json
{
  "status": "success",
  "diagnostics": {
    "conflicts_per_lecturer": {
      "Dr. Smith": 0,
      "Dr. Johnson": 1
    },
    "room_utilization": {
      "CS Lab A": 0.85,
      "General Room 101": 0.62
    },
    "slot_contention": {
      "MON-10am": 0.92,
      "TUE-2pm": 0.75
    },
    "recommendations": [
      "Reduce Monday 10am load by 15%",
      "Consolidate afternoon courses to fewer rooms"
    ]
  }
}
```

### GET /analytics/performance
Get overall performance metrics and improvements.

**Response:**
```json
{
  "status": "success",
  "metrics": {
    "total_schedules_generated": 127,
    "average_accuracy": 85.0,
    "success_rate": 0.92,
    "avg_generation_time": "45s",
    "ai_improvements": [
      {
        "metric": "Conflict resolution",
        "improvement": "+35%"
      },
      {
        "metric": "Room utilization",
        "improvement": "+28%"
      },
      {
        "metric": "Lecturer preference match",
        "improvement": "+42%"
      }
    ]
  },
  "timestamp": "2026-02-14T10:30:00.000Z"
}
```

### POST /explain/schedule
Get AI explainability for why specific courses were scheduled at certain times (SHAP-based insights).

**Request Body:**
```json
{
  "schedule_id": "final_web_schedule.csv"
}
```

**Response:**
```json
{
  "status": "success",
  "schedule_id": "final_web_schedule.csv",
  "explanation": {
    "why_this_slot": [
      "Lecturer availability (high confidence)",
      "Historical preference data (75% match)",
      "Room capacity optimization (82% utilization)"
    ],
    "feature_importance": {
      "lecturer_availability": 0.35,
      "room_capacity": 0.28,
      "historical_preference": 0.22,
      "conflict_avoidance": 0.15
    },
    "alternatives_considered": [
      {
        "slot": "TUE-10am",
        "score": 0.72,
        "reason": "Lecturer conflict"
      },
      {
        "slot": "WED-2pm",
        "score": 0.68,
        "reason": "Room too small"
      }
    ]
  },
  "confidence": 0.87
}
```

---

## Usage Examples

### Python (Requests Library)
```python
import requests

# Check health
response = requests.get('http://localhost:5000/health')
print(response.json())

# Generate schedule
payload = {
    'input_file': 'departmental_courses.csv',
    'output_file': 'final_web_schedule.csv',
    'course_type': 'Departmental',
    'department': '1'
}
response = requests.post('http://localhost:5000/generate', json=payload)
print(response.json())

# Get quality prediction
features = {
    'num_events': 45,
    'total_hours': 180.0,
    'morning_load': 0.35,
    'afternoon_load': 0.42,
    'evening_load': 0.23
}
response = requests.post('http://localhost:5000/predict/quality', json=features)
quality = response.json()['quality']
print(f"Schedule Quality: {quality['overall_score']}/10 ({quality['category']})")
```

### JavaScript (Fetch API)
```javascript
// Check health
fetch('http://localhost:5000/health')
  .then(res => res.json())
  .then(data => console.log(data.status));

// Generate schedule
fetch('http://localhost:5000/generate', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    input_file: 'departmental_courses.csv',
    output_file: 'final_web_schedule.csv',
    course_type: 'Departmental',
    department: '1'
  })
})
.then(res => res.json())
.then(data => console.log(`Success: ${data.accuracy}`));
```

### cURL
```bash
# Check health
curl http://localhost:5000/health

# Generate schedule
curl -X POST http://localhost:5000/generate \
  -H "Content-Type: application/json" \
  -d '{
    "input_file": "departmental_courses.csv",
    "output_file": "final_web_schedule.csv",
    "course_type": "Departmental",
    "department": "1"
  }'

# Get progress
curl http://localhost:5000/progress

# Predict quality
curl -X POST http://localhost:5000/predict/quality \
  -H "Content-Type: application/json" \
  -d '{
    "num_events": 45,
    "total_hours": 180.0,
    "morning_load": 0.35,
    "afternoon_load": 0.42,
    "evening_load": 0.23
  }'
```

---

## Error Handling

All endpoints return standard HTTP status codes:
- `200`: Success
- `400`: Bad Request (invalid parameters)
- `404`: Not Found (file not found)
- `500`: Internal Server Error

Error responses include:
```json
{
  "status": "error",
  "message": "Detailed error description"
}
```

---

## Rate Limiting & Performance

- **No rate limiting** for local use
- **30-second timeout** on schedule generation
- **Real-time progress tracking** via `/progress` endpoint
- **Parallel requests** supported (independent jobs)

---

## AI Component Details

| Component | Purpose | Algorithm | Status |
|-----------|---------|-----------|--------|
| **CSP Solver** | Find conflict-free schedules | Backtracking + MRV | ✅ Production |
| **Deep Learning** | Predict schedule quality | Neural Network | ✅ Ready |
| **Q-Learning** | Learn user preferences | Reinforcement Learning | ✅ Active |
| **Feasibility Classifier** | Pre-validate assignments | Random Forest | ✅ Trained |
| **Diagnostics** | Analyze schedule issues | Statistical Analysis | ✅ Available |
| **Bidirectional Feedback** | Two-way learning system | Multi-system sync | ✅ Connected |

---

## Integration Checklist

- [x] Health/Status endpoints configured
- [x] Schedule generation endpoints working
- [x] Quality prediction integrated
- [x] Feasibility checking enabled
- [x] Feedback recording system active
- [x] Analytics endpoints deployed
- [x] Diagnostics module connected
- [x] Explainability features enabled
- [x] Error handling implemented
- [x] CORS enabled for frontend access

---

**Last Updated:** February 2026  
**Version:** 2.0 (AI Enhanced)  
**Maintainer:** AI Scheduling Team
