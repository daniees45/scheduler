import json
import sys
from datetime import datetime, time, timedelta
from typing import List

from personal_scheduler import BusyBlock, build_suggestions_from_blocks, parse_time_safe


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


def main() -> int:
    payload = _load_input()
    role = str(payload.get("role", "student"))
    schedule_rows = payload.get("schedule_rows", []) or []
    personal_events = payload.get("personal_events", []) or []
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

    output = []
    for s in suggestions:
        output.append({
            "day": s.day,
            "start_time": s.start.strftime("%H:%M"),
            "end_time": s.end.strftime("%H:%M"),
            "duration_minutes": _to_minutes(s.start, s.end),
            "score": round(float(s.score), 2),
            "reason": s.reason,
            "title": s.title,
        })

    print(json.dumps({"success": True, "suggestions": output}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
