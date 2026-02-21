import pandas as pd
import os
import re


def _find_col(df, candidates):
    """Return the first matching column name from candidates (case-insensitive)."""
    if df is None or df.empty:
        return None
    cols = {str(c).strip().lower(): c for c in df.columns}
    for cand in candidates:
        key = str(cand).strip().lower()
        if key in cols:
            return cols[key]
    return None

def parse_time_slot(time_str):
    """
    Parses "8:00 AM - 11:00 AM" into start hour (24h).
    Returns integer hour (e.g., 8, 13, 16).
    """
    if not isinstance(time_str, str):
        return 0
    
    # Extract start time
    parts = time_str.split('-')
    if not parts:
        return 0
    
    start_str = parts[0].strip()
    
    # Parse "8:00 AM"
    match = re.match(r"(\d+):(\d+)\s*(AM|PM)", start_str, re.IGNORECASE)
    if match:
        h, m, period = match.groups()
        h = int(h)
        if period.upper() == 'PM' and h != 12:
            h += 12
        if period.upper() == 'AM' and h == 12:
            h = 0
        return h
    return 0

def analyze_schedule(schedule_csv):
    """
    Analyzes the generated schedule CSV and returns a dictionary of metrics and recommendations.
    """
    results = {
        "status": "success",
        "metrics": {
            "total_events": 0,
            "morning_load": 0.0,
            "afternoon_load": 0.0,
            "evening_load": 0.0,
            "room_utilization": 0.0,
            "lecturer_conflicts": 0,
            "ai_efficiency": 0.0,
            "balance_score": 0.0
        },
        "recommendations": []
    }

    if not os.path.exists(schedule_csv):
        results["status"] = "error"
        results["message"] = "File not found"
        return results

    try:
        df = pd.read_csv(schedule_csv)
        if df.empty:
            return results
        
        # Resolve likely column names from different export formats
        time_col = _find_col(df, ['Time', 'time', 'start_time'])
        room_col = _find_col(df, ['Room Name', 'room_name', 'room'])
        lect_col = _find_col(df, ['Lecturer Name', 'lecturer_name', 'lecturer'])
        day_col = _find_col(df, ['Day', 'day'])

        # 1. Time Distribution
        total = len(df)
        results["metrics"]["total_events"] = total
        
        morning = 0
        afternoon = 0
        evening = 0
        
        for _, row in df.iterrows():
            t = str(row.get(time_col, '')) if time_col else ''
            h = parse_time_slot(t)
            if h < 12:
                morning += 1
            elif h < 16:
                afternoon += 1
            else:
                evening += 1
                
        if total > 0:
            results["metrics"]["morning_load"] = round(morning / total, 2)
            results["metrics"]["afternoon_load"] = round(afternoon / total, 2)
            results["metrics"]["evening_load"] = round(evening / total, 2)

        # 2. Room utilization (dynamic fairness score based on room load spread)
        room_util_pct = 0.0
        if room_col:
            room_counts = df[room_col].astype(str).str.strip().value_counts()
            if not room_counts.empty:
                used_rooms = len(room_counts)
                mean_load = float(room_counts.mean()) if used_rooms > 0 else 0.0
                std_load = float(room_counts.std()) if used_rooms > 1 else 0.0
                fairness = 1.0 if mean_load <= 0 else max(0.0, min(1.0, 1.0 - (std_load / (mean_load + 1e-9))))
                coverage = max(0.0, min(1.0, used_rooms / max(used_rooms, 1)))
                room_util_pct = round(((0.75 * fairness) + (0.25 * coverage)) * 100.0, 2)
        results["metrics"]["room_utilization"] = room_util_pct

        # 3. Lecturer conflicts (same lecturer, same day, same slot assigned >1 course)
        lecturer_conflicts = 0
        if lect_col and day_col and time_col:
            conflict_series = (
                df.groupby([lect_col, day_col, time_col])
                  .size()
            )
            lecturer_conflicts = int(conflict_series[conflict_series > 1].sum() - len(conflict_series[conflict_series > 1]))
            lecturer_conflicts = max(0, lecturer_conflicts)
        results["metrics"]["lecturer_conflicts"] = lecturer_conflicts

        # 4. Aggregate scores
        balance_spread = max(
            results["metrics"]["morning_load"],
            results["metrics"]["afternoon_load"],
            results["metrics"]["evening_load"]
        ) - min(
            results["metrics"]["morning_load"],
            results["metrics"]["afternoon_load"],
            results["metrics"]["evening_load"]
        )
        balance_score = max(0.0, min(1.0, 1.0 - balance_spread))
        conflict_free = max(0.0, min(1.0, 1.0 - (lecturer_conflicts / max(total, 1))))
        room_score = max(0.0, min(1.0, room_util_pct / 100.0))

        ai_eff = ((0.40 * room_score) + (0.35 * balance_score) + (0.25 * conflict_free)) * 100.0
        results["metrics"]["balance_score"] = round(balance_score * 100.0, 2)
        results["metrics"]["ai_efficiency"] = round(ai_eff, 2)

        # 5. Recommendations
        recs = []
        
        # Check load balance
        if results["metrics"]["morning_load"] > 0.6:
            recs.append({
                "type": "optimization",
                "title": "High Morning Load",
                "description": f"Morning slots are {int(results['metrics']['morning_load']*100)}% of schedule. Consider moving sections to afternoon.",
                "priority": "high"
            })
        
        if results["metrics"]["evening_load"] > 0.4:
             recs.append({
                "type": "optimization",
                "title": "High Evening Load",
                "description": "Evening classes are high. Ensure security and lighting are adequate.",
                "priority": "medium"
            })

        # Check Room Usage (Simple count)
        if room_col:
            room_counts = df[room_col].astype(str).str.strip().value_counts()
            overused = room_counts[room_counts > 15] # Arbitrary threshold per week
            if not overused.empty:
                 recs.append({
                    "type": "efficiency",
                    "title": "Room Contention",
                    "description": f"{len(overused)} rooms are heavily booked (>15 slots).",
                    "priority": "medium"
                })
        
        # Check Lecturer Load
        if lect_col:
            lect_counts = df[lect_col].astype(str).str.strip().value_counts()
            overloaded = lect_counts[lect_counts > 12] # > 12 hours/slots
            if not overloaded.empty:
                 recs.append({
                    "type": "workload",
                    "title": "Lecturer Overload",
                    "description": f"{len(overloaded)} lecturers have >12 assigned slots.",
                    "priority": "high"
                })

        if lecturer_conflicts > 0:
            recs.append({
                "type": "conflict",
                "title": "Lecturer Slot Conflicts",
                "description": f"Detected {lecturer_conflicts} overlapping lecturer assignment(s).",
                "priority": "high"
            })

        if ai_eff < 65:
            recs.append({
                "type": "efficiency",
                "title": "Low AI Efficiency",
                "description": f"Overall efficiency is {round(ai_eff, 1)}%. Rebalance loads and room allocation for better outcomes.",
                "priority": "medium"
            })
                
        results["recommendations"] = recs
        
    except Exception as e:
        results["status"] = "error"
        results["message"] = str(e)
        
    return results

if __name__ == "__main__":
    import sys
    import json
    if len(sys.argv) > 1:
        print(json.dumps(analyze_schedule(sys.argv[1]), indent=2))
