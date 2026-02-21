from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from datetime import datetime, time
from typing import Dict, List, Optional, Any
import os
import uuid

try:
    from personal_scheduler import BusyBlock, build_personal_schedule, parse_time_safe
except Exception:
    BusyBlock = None
    build_personal_schedule = None
    parse_time_safe = None

try:
    from deep_learning import (
        ScheduleFeatures,
        ScheduleQuality,
        get_classifier,
        get_bidirectional_feedback,
    )
except Exception:
    ScheduleFeatures = None
    ScheduleQuality = None
    get_classifier = None
    get_bidirectional_feedback = None

app = FastAPI(
    title="Schedule Assistant API",
    version="1.0.0",
    description="AI-powered schedule suggestions"
)

# CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# In-memory storage
events_store: Dict[str, Dict[str, Any]] = {}

# ==================== ENDPOINTS ====================

class EventPayload(BaseModel):
    title: str = Field(..., min_length=1)
    day: str = Field(..., min_length=1)
    start_time: str = Field(..., min_length=1)
    end_time: str = Field(..., min_length=1)
    category: str = Field(..., min_length=1)
    location: Optional[str] = None
    priority: int = 1


class FeedbackPayload(BaseModel):
    action: str = Field(..., description="accept or reject")
    suggestion_id: Optional[str] = None
    reason: Optional[str] = None


class SuggestPayload(BaseModel):
    role: str = Field("student", description="student or staff")


def _project_root() -> str:
    return os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))


def _parse_time(value: str) -> Optional[time]:
    if parse_time_safe:
        return parse_time_safe(value)
    for fmt in ["%H:%M", "%I:%M %p", "%I %p"]:
        try:
            return datetime.strptime(value.strip(), fmt).time()
        except ValueError:
            continue
    return None


def _event_duration_hours(start_t: time, end_t: time) -> float:
    start_dt = datetime.combine(datetime.today(), start_t)
    end_dt = datetime.combine(datetime.today(), end_t)
    if end_dt <= start_dt:
        return 0.0
    return (end_dt - start_dt).total_seconds() / 3600.0


def _compute_features(events: List[Dict[str, Any]]):
    if ScheduleFeatures is None:
        return None

    parsed_events = []
    for event in events:
        start_t = _parse_time(str(event.get("start_time", "")))
        end_t = _parse_time(str(event.get("end_time", "")))
        day = str(event.get("day", "")).strip()
        if not (start_t and end_t and day):
            continue
        parsed_events.append({"start": start_t, "end": end_t, "day": day})

    num_events = len(parsed_events)
    if num_events == 0:
        return ScheduleFeatures()

    total_hours = 0.0
    morning_load = afternoon_load = evening_load = 0.0
    duration_list: List[float] = []
    conflict_count = 0
    gap_total = 0.0
    gap_count = 0
    hour_counts: Dict[int, int] = {}

    events_by_day: Dict[str, List[Dict[str, Any]]] = {}
    for item in parsed_events:
        events_by_day.setdefault(item["day"], []).append(item)

    for day_events in events_by_day.values():
        day_events.sort(key=lambda e: e["start"])
        for idx, item in enumerate(day_events):
            start_t = item["start"]
            end_t = item["end"]
            duration = _event_duration_hours(start_t, end_t)
            total_hours += duration
            duration_list.append(duration)

            start_hour = start_t.hour
            hour_counts[start_hour] = hour_counts.get(start_hour, 0) + 1

            if start_hour < 12:
                morning_load += duration
            elif start_hour < 17:
                afternoon_load += duration
            else:
                evening_load += duration

            if idx > 0:
                prev = day_events[idx - 1]
                prev_end = prev["end"]
                if start_t < prev_end:
                    conflict_count += 1
                else:
                    gap = _event_duration_hours(prev_end, start_t)
                    gap_total += gap
                    gap_count += 1

    avg_gap = gap_total / gap_count if gap_count else 0.0
    avg_duration = sum(duration_list) / len(duration_list) if duration_list else 0.0

    peak_hours = sorted(hour_counts, key=hour_counts.get, reverse=True)[:3]

    return ScheduleFeatures(
        num_events=num_events,
        total_hours=total_hours,
        avg_gap_between=avg_gap,
        morning_load=morning_load,
        afternoon_load=afternoon_load,
        evening_load=evening_load,
        num_conflicts=conflict_count,
        avg_event_duration=avg_duration,
        peak_productivity_hours=peak_hours,
    )


@app.get("/")
async def root():
    """API root - check if server is running"""
    return {
        "status": "online",
        "name": "Schedule Assistant API",
        "version": "1.0.0",
        "docs": "/docs",
        "endpoints": {
            "health": "/health",
            "events": "/events",
            "suggestions": "/suggest"
        }
    }

@app.get("/health")
async def health():
    """Health check endpoint"""
    return {"status": "healthy", "timestamp": datetime.now().isoformat()}

# ==================== EVENTS ====================

@app.get("/events")
async def list_events():
    """Get all events"""
    return {
        "events": list(events_store.values()),
        "count": len(events_store)
    }

@app.post("/events")
async def create_event(event: EventPayload):
    """Create a new event"""
    event_id = f"evt_{uuid.uuid4().hex[:8]}"
    payload = event.model_dump()
    payload["id"] = event_id
    payload["created_at"] = datetime.now().isoformat()
    events_store[event_id] = payload
    
    return {
        "success": True,
        "event": payload
    }

@app.get("/events/{event_id}")
async def get_event(event_id: str):
    """Get specific event"""
    if event_id not in events_store:
        raise HTTPException(status_code=404, detail="Event not found")
    return {"event": events_store[event_id]}

@app.delete("/events/{event_id}")
async def delete_event(event_id: str):
    """Delete an event"""
    if event_id not in events_store:
        raise HTTPException(status_code=404, detail="Event not found")
    del events_store[event_id]
    return {"message": "Event deleted"}

@app.put("/events/{event_id}")
async def update_event(event_id: str, event: EventPayload):
    """Update an event"""
    if event_id not in events_store:
        raise HTTPException(status_code=404, detail="Event not found")
    payload = event.model_dump()
    payload["id"] = event_id
    payload["updated_at"] = datetime.now().isoformat()
    events_store[event_id] = payload
    return {"success": True, "event": payload}


# ==================== AI SUGGESTIONS ====================

@app.post("/suggest")
async def get_suggestions(payload: SuggestPayload):
    """Get AI suggestions for current schedule"""
    if not events_store:
        raise HTTPException(status_code=400, detail="No events to analyze")
    
    events_list = list(events_store.values())
    suggestion_payload: Dict[str, Any] = {
        "id": f"sugg_{uuid.uuid4().hex[:8]}",
        "message": f"Analyzed {len(events_list)} events.",
        "quality_score": 0.5,
        "recommendations": [],
        "created_at": datetime.now().isoformat(),
        "ai_quality": None,
        "schedule_suggestions": [],
    }

    # AI quality prediction
    features = _compute_features(events_list)
    if features and get_classifier:
        classifier = get_classifier()
        quality = classifier.predict(features)
        suggestion_payload["ai_quality"] = {
            "overall_score": quality.overall_score,
            "category": quality.category,
            "completion_probability": quality.completion_probability,
            "conflict_severity": quality.conflict_severity,
            "confidence": quality.confidence,
        }
        suggestion_payload["recommendations"] = quality.optimization_suggestions
        suggestion_payload["quality_score"] = quality.overall_score
        suggestion_payload["message"] = f"Quality: {quality.category} ({quality.overall_score:.2f})."
    else:
        suggestion_payload["recommendations"] = [
            "Add a 15-minute break between tasks",
            "Schedule deep work in the morning",
        ]

    # Schedule suggestions from CSP solver
    if build_personal_schedule and BusyBlock:
        personal_blocks = []
        for event in events_list:
            start_t = _parse_time(str(event.get("start_time", "")))
            end_t = _parse_time(str(event.get("end_time", "")))
            day = str(event.get("day", "")).strip()
            if not (start_t and end_t and day):
                continue
            personal_blocks.append(
                BusyBlock(
                    day=day,
                    start=start_t,
                    end=end_t,
                    label=str(event.get("title", "Event")),
                    source="personal",
                )
            )
        base_dir = _project_root()
        _, suggestions = build_personal_schedule(
            base_dir=base_dir,
            personal_events=personal_blocks,
            role=payload.role,
        )
        suggestion_payload["schedule_suggestions"] = [
            {
                "day": s.day,
                "start": s.start.strftime("%H:%M"),
                "end": s.end.strftime("%H:%M"),
                "title": s.title,
                "reason": s.reason,
                "score": s.score,
            }
            for s in suggestions[:5]
        ]

    return {
        "success": True,
        "suggestion": suggestion_payload,
    }

# ==================== FEEDBACK ====================

@app.post("/feedback")
async def submit_feedback(feedback: FeedbackPayload):
    """Record user feedback on suggestions"""
    if get_bidirectional_feedback and ScheduleFeatures:
        features = _compute_features(list(events_store.values())) or ScheduleFeatures()
        bidirectional = get_bidirectional_feedback()
        bidirectional.record_user_feedback(
            schedule_features=features,
            user_action=feedback.action,
            schedule_quality=None,
            additional_data={
                "suggestion_id": feedback.suggestion_id,
                "reason": feedback.reason,
            },
        )
    return {
        "success": True,
        "message": f"Feedback recorded: {feedback.action}",
        "timestamp": datetime.now().isoformat(),
    }


@app.get("/ai/status")
async def ai_status():
    """Get AI system status"""
    status = {
        "deep_learning_available": ScheduleFeatures is not None,
        "scheduler_available": build_personal_schedule is not None,
    }
    if get_bidirectional_feedback:
        status["feedback_state"] = get_bidirectional_feedback().get_system_state()
    return status

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8010)
