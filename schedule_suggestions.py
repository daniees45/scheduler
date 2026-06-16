import json
import sys
from datetime import datetime, time, timedelta
from typing import List

from personal_scheduler import BusyBlock, build_suggestions_from_blocks, parse_time_safe

try:
    from q_learner_integration import rank_suggestions_by_preference, get_preference_score, get_q_learner_statistics
except ImportError:
    rank_suggestions_by_preference = None
    get_preference_score = None
    get_q_learner_statistics = None

try:
    from deep_learning import get_classifier, ScheduleFeatures
except ImportError:
    get_classifier = None
    ScheduleFeatures = None


def _parse_time_range(value: str):
    if not value:
        return None, None
    if "-" not in value:
        start_t = parse_time_safe(value)
        if not start_t:
            return None, None
        end_dt = datetime.combine(datetime.today(), start_t) + timedelta(hours=1)
        return start_t, end_dt.time()
    left, right = [v.strip() for v in value.split("-", 1)]
    return parse_time_safe(left), parse_time_safe(right)


def _parse_day(value: str) -> str:
    if not value:
        return ""
    value = value.strip()
    return value[:1].upper() + value[1:]


def _load_input() -> dict:
    raw = sys.stdin.read()
    if not raw:
        return {}
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        return {}


def _time_from_str(value: str, fallback: time) -> time:
    parsed = parse_time_safe(value) if value else None
    return parsed or fallback


def _to_minutes(start_t: time, end_t: time) -> int:
    start_dt = datetime.combine(datetime.today(), start_t)
    end_dt = datetime.combine(datetime.today(), end_t)
    if end_dt <= start_dt:
        end_dt = start_dt + timedelta(hours=1)
    return int((end_dt - start_dt).total_seconds() / 60)


def _compute_schedule_features(busy_blocks: List[BusyBlock], payload: dict):
    if ScheduleFeatures is None:
        return None

    durations = []
    conflicts = 0
    morning_load = 0.0
    afternoon_load = 0.0
    evening_load = 0.0
    day_grouped = {}

    for block in busy_blocks:
        duration_minutes = _to_minutes(block.start, block.end)
        durations.append(duration_minutes / 60.0)
        day_grouped.setdefault(block.day, []).append(block)

        if block.start.hour < 12:
            morning_load += duration_minutes / 60.0
        elif block.start.hour < 17:
            afternoon_load += duration_minutes / 60.0
        else:
            evening_load += duration_minutes / 60.0

    avg_gap = 0.0
    gap_count = 0
    for blocks in day_grouped.values():
        ordered = sorted(blocks, key=lambda item: item.start)
        for idx in range(1, len(ordered)):
            prev = ordered[idx - 1]
            current = ordered[idx]
            prev_end = datetime.combine(datetime.today(), prev.end)
            cur_start = datetime.combine(datetime.today(), current.start)
            gap_hours = max(0.0, (cur_start - prev_end).total_seconds() / 3600.0)
            avg_gap += gap_hours
            gap_count += 1
            if current.start < prev.end:
                conflicts += 1

    productivity = payload.get("productivity", {}) or {}
    q_stats = {}
    if callable(get_q_learner_statistics):
        try:
            q_stats = get_q_learner_statistics() or {}
        except Exception:
            q_stats = {}

    features = ScheduleFeatures(
        num_events=len(busy_blocks),
        total_hours=sum(durations),
        avg_gap_between=(avg_gap / gap_count) if gap_count else 0.0,
        morning_load=morning_load,
        afternoon_load=afternoon_load,
        evening_load=evening_load,
        num_conflicts=conflicts,
        avg_event_duration=(sum(durations) / len(durations)) if durations else 0.0,
        q_learner_accept_rate=float(q_stats.get("accept_rate", productivity.get("accept_rate", 0.5)) or 0.5),
        q_learner_confidence=float(productivity.get("q_confidence", q_stats.get("avg_reward", 0.5)) or 0.5),
        num_learned_preferences=int(productivity.get("learned_preferences", q_stats.get("learned_preferences", 0)) or 0),
        peak_productivity_hours=productivity.get("peak_productivity_hours", []) or [],
        avg_quality_rating=float(productivity.get("avg_quality_rating", 0.0) or 0.0),
        completion_rate=float(productivity.get("completion_rate", 0.0) or 0.0),
        user_load_factor=min(1.0, (sum(durations) / 40.0) if durations else 0.0),
    )

    return features


def main() -> int:
    payload = _load_input()
    role = str(payload.get("role", "student"))
    schedule_rows = payload.get("schedule_rows", []) or []
    personal_events = payload.get("personal_events", []) or []
    task_category = str(payload.get("task_category", "study"))
    min_minutes = int(payload.get("min_minutes", 60) or 60)
    day_start = _time_from_str(str(payload.get("day_start", "07:00")), time(7, 0))
    day_end = _time_from_str(str(payload.get("day_end", "21:00")), time(21, 0))

    busy_blocks: List[BusyBlock] = []

    for row in schedule_rows:
        day = _parse_day(str(row.get("day", "")))
        time_range = str(row.get("time", ""))
        label = str(row.get("label", "Lecture")).strip() or "Lecture"
        start_t, end_t = _parse_time_range(time_range)
        if not day or not start_t or not end_t:
            continue
        busy_blocks.append(BusyBlock(day=day, start=start_t, end=end_t, label=label, source="schedule"))

    for ev in personal_events:
        day = _parse_day(str(ev.get("day", "")))
        start_t = parse_time_safe(str(ev.get("start_time", "")))
        end_t = parse_time_safe(str(ev.get("end_time", "")))
        label = str(ev.get("title", "Personal Event")).strip() or "Personal Event"
        if not day or not start_t or not end_t:
            continue
        busy_blocks.append(BusyBlock(day=day, start=start_t, end=end_t, label=label, source="personal"))

    suggestions = build_suggestions_from_blocks(
        busy_blocks=busy_blocks,
        role=role,
        min_minutes=min_minutes,
        day_start=day_start,
        day_end=day_end,
    )

    if callable(rank_suggestions_by_preference):
        try:
            ranked = rank_suggestions_by_preference(suggestions, task_category=task_category)
            suggestions = [item[0] for item in ranked]
            final_scores = {id(item[0]): float(item[1]) for item in ranked}
        except Exception:
            final_scores = {id(s): float(s.score) for s in suggestions}
    else:
        final_scores = {id(s): float(s.score) for s in suggestions}

    features = _compute_schedule_features(busy_blocks, payload)
    quality_prediction = None
    if features is not None and callable(get_classifier):
        try:
            prediction = get_classifier().predict(features)
            quality_prediction = {
                "overall_score": round(float(prediction.overall_score), 3),
                "category": prediction.category,
                "completion_probability": round(float(prediction.completion_probability), 3),
                "conflict_severity": round(float(prediction.conflict_severity), 3),
                "confidence": round(float(prediction.confidence), 3),
                "optimization_suggestions": prediction.optimization_suggestions,
            }
        except Exception:
            quality_prediction = None

    output = []
    for s in suggestions:
        preference_score = 0.5
        if callable(get_preference_score):
            try:
                preference_score = float(get_preference_score(s.day, s.start.hour, task_category=task_category))
            except Exception:
                preference_score = 0.5
        output.append({
            "day": s.day,
            "start_time": s.start.strftime("%H:%M"),
            "end_time": s.end.strftime("%H:%M"),
            "duration_minutes": _to_minutes(s.start, s.end),
            "score": round(float(s.score), 2),
            "final_score": round(float(final_scores.get(id(s), s.score)), 2),
            "preference_score": round(preference_score, 3),
            "reason": s.reason,
            "title": s.title,
        })

    print(json.dumps({
        "success": True,
        "suggestions": output,
        "quality_prediction": quality_prediction,
    }))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
