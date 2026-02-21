import os
import csv
from dataclasses import dataclass
from datetime import datetime, time, timedelta
from typing import Dict, List, Tuple, Iterable


DAY_ORDER = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
DAY_INDEX = {day: idx for idx, day in enumerate(DAY_ORDER)}


@dataclass
class BusyBlock:
    day: str
    start: time
    end: time
    label: str
    source: str


@dataclass
class Suggestion:
    day: str
    start: time
    end: time
    title: str
    reason: str
    score: float = 0.0


def _parse_time_component(value: str) -> time:
    value = value.strip()
    for fmt in ["%I:%M %p", "%H:%M", "%I %p"]:
        try:
            return datetime.strptime(value, fmt).time()
        except ValueError:
            continue
    raise ValueError(f"Unrecognized time format: {value}")


def parse_time_safe(value: str):
    if not value:
        return None
    for fmt in ["%H:%M", "%I:%M %p", "%I %p"]:
        try:
            return datetime.strptime(value.strip(), fmt).time()
        except ValueError:
            continue
    return None


def _parse_time_range(value: str) -> Tuple[time, time]:
    if "-" in value:
        left, right = [v.strip() for v in value.split("-", 1)]
        return _parse_time_component(left), _parse_time_component(right)
    raise ValueError(f"Unrecognized time range: {value}")


def _load_csv_blocks(path: str, label_prefix: str, source: str) -> List[BusyBlock]:
    blocks: List[BusyBlock] = []
    if not os.path.exists(path):
        return blocks

    with open(path, newline="", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            day = row.get("Day") or row.get("day")
            time_range = row.get("Time") or row.get("time")
            if not day or not time_range:
                continue
            try:
                start_t, end_t = _parse_time_range(str(time_range))
            except ValueError:
                continue
            title = row.get("Course Code") or row.get("Course Code & Title") or row.get("Course Title") or ""
            label = f"{label_prefix}{title}".strip()
            blocks.append(BusyBlock(day=day, start=start_t, end=end_t, label=label, source=source))

    return blocks


def load_institution_blocks(base_dir: str, timetable_path: str | None = None, exam_path: str | None = None) -> List[BusyBlock]:
    timetable_path = timetable_path or os.path.join(base_dir, "final_web_schedule.csv")
    exam_path = exam_path or os.path.join(base_dir, "final_exam_schedule.csv")
    fallback_dir = os.path.abspath(os.path.join(base_dir, os.pardir))

    if not os.path.exists(timetable_path):
        timetable_path = os.path.join(fallback_dir, "final_web_schedule.csv")
    if not os.path.exists(exam_path):
        exam_path = os.path.join(fallback_dir, "final_exam_schedule.csv")

    blocks = []
    blocks.extend(_load_csv_blocks(timetable_path, "Class: ", "timetable"))
    blocks.extend(_load_csv_blocks(exam_path, "Exam: ", "exam"))
    return blocks


def detect_conflicts(candidate: BusyBlock, busy_blocks: Iterable[BusyBlock]) -> List[BusyBlock]:
    conflicts: List[BusyBlock] = []
    for block in busy_blocks:
        if block.day != candidate.day:
            continue
        if candidate.start < block.end and candidate.end > block.start:
            conflicts.append(block)
    return conflicts


def _merge_blocks(blocks: Iterable[BusyBlock]) -> Dict[str, List[BusyBlock]]:
    grouped: Dict[str, List[BusyBlock]] = {day: [] for day in DAY_ORDER}
    for block in blocks:
        if block.day not in grouped:
            grouped[block.day] = []
        grouped[block.day].append(block)

    for day, day_blocks in grouped.items():
        day_blocks.sort(key=lambda b: b.start)
        merged: List[BusyBlock] = []
        for block in day_blocks:
            if not merged:
                merged.append(block)
                continue
            last = merged[-1]
            if block.start <= last.end:
                last.end = max(last.end, block.end)
            else:
                merged.append(block)
        grouped[day] = merged

    return grouped


def _build_free_blocks(merged_blocks: Dict[str, List[BusyBlock]],
                       day_start: time,
                       day_end: time) -> Dict[str, List[Tuple[time, time]]]:
    free: Dict[str, List[Tuple[time, time]]] = {day: [] for day in DAY_ORDER}
    for day in DAY_ORDER:
        blocks = merged_blocks.get(day, [])
        cursor = day_start
        for block in blocks:
            if block.start > cursor:
                free[day].append((cursor, block.start))
            cursor = max(cursor, block.end)
        if cursor < day_end:
            free[day].append((cursor, day_end))
    return free


def _filter_free_blocks(free: Dict[str, List[Tuple[time, time]]], min_minutes: int) -> Dict[str, List[Tuple[time, time]]]:
    filtered: Dict[str, List[Tuple[time, time]]] = {day: [] for day in DAY_ORDER}
    for day, blocks in free.items():
        for start_t, end_t in blocks:
            start_dt = datetime.combine(datetime.today(), start_t)
            end_dt = datetime.combine(datetime.today(), end_t)
            if (end_dt - start_dt).total_seconds() >= min_minutes * 60:
                filtered[day].append((start_t, end_t))
    return filtered


def _score_free_block(start_t: time, end_t: time, role: str) -> Tuple[float, str]:
    start_dt = datetime.combine(datetime.today(), start_t)
    end_dt = datetime.combine(datetime.today(), end_t)
    duration_minutes = (end_dt - start_dt).total_seconds() / 60.0

    midpoint = start_dt + (end_dt - start_dt) / 2
    midpoint_hour = midpoint.hour + midpoint.minute / 60.0

    if role == "student":
        preferred_start, preferred_end = 9.0, 18.0
    else:
        preferred_start, preferred_end = 10.0, 16.0

    if preferred_start <= midpoint_hour <= preferred_end:
        time_bonus = 20.0
    else:
        distance = min(abs(midpoint_hour - preferred_start), abs(midpoint_hour - preferred_end))
        time_bonus = max(0.0, 20.0 - distance * 5.0)

    duration_bonus = min(duration_minutes / 30.0, 8.0) * 3.0
    focus_bonus = 10.0 if duration_minutes >= 90 else 0.0
    score = duration_minutes + time_bonus + duration_bonus + focus_bonus

    reason = f"Score {score:.1f}: duration {duration_minutes:.0f} mins, time bonus {time_bonus:.0f}."
    return score, reason


def build_personal_schedule(base_dir: str,
                            personal_events: Iterable[BusyBlock],
                            role: str,
                            min_minutes: int = 60,
                            day_start: time = time(7, 0),
                            day_end: time = time(21, 0),
                            timetable_path: str | None = None,
                            exam_path: str | None = None) -> Tuple[List[BusyBlock], List[Suggestion]]:
    busy_blocks = []
    busy_blocks.extend(load_institution_blocks(base_dir, timetable_path=timetable_path, exam_path=exam_path))
    busy_blocks.extend(personal_events)

    merged = _merge_blocks(busy_blocks)
    free = _build_free_blocks(merged, day_start, day_end)
    free = _filter_free_blocks(free, min_minutes)

    suggestions: List[Suggestion] = []
    for day, blocks in free.items():
        for start_t, end_t in blocks:
            title = "Study Session" if role == "student" else "Office Hour"
            score, reason = _score_free_block(start_t, end_t, role)
            suggestions.append(Suggestion(day=day, start=start_t, end=end_t, title=title, reason=reason, score=score))

    suggestions.sort(key=lambda s: (-s.score, DAY_INDEX.get(s.day, 99), s.start))

    return busy_blocks, suggestions


def build_suggestions_from_blocks(busy_blocks: Iterable[BusyBlock],
                                  role: str,
                                  min_minutes: int = 60,
                                  day_start: time = time(7, 0),
                                  day_end: time = time(21, 0)) -> List[Suggestion]:
    """Build suggestions directly from provided busy blocks."""
    merged = _merge_blocks(busy_blocks)
    free = _build_free_blocks(merged, day_start, day_end)
    free = _filter_free_blocks(free, min_minutes)

    suggestions: List[Suggestion] = []
    for day, blocks in free.items():
        for start_t, end_t in blocks:
            title = "Study Session" if role == "student" else "Office Hour"
            score, reason = _score_free_block(start_t, end_t, role)
            suggestions.append(Suggestion(day=day, start=start_t, end=end_t, title=title, reason=reason, score=score))

    suggestions.sort(key=lambda s: (-s.score, DAY_INDEX.get(s.day, 99), s.start))
    return suggestions
