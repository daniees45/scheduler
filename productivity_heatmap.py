"""
PRODUCTIVITY HEATMAP MODULE
Visualizes user productivity patterns by time of day and day of week
Helps identify high-productivity periods for task scheduling
"""

import os
import json
import csv
from datetime import datetime, time, timedelta
from typing import Dict, List, Tuple
import numpy as np

try:
    import matplotlib.pyplot as plt
    import matplotlib.patches as mpatches
    MATPLOTLIB_AVAILABLE = True
except ImportError:
    MATPLOTLIB_AVAILABLE = False
    print("[WARNING] matplotlib not installed. Heatmap visualization will be text-based.")

# ============================================================================
# CONFIGURATION
# ============================================================================

OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "tkinter_app", "output")
PRODUCTIVITY_LOG_PATH = os.path.join(OUTPUT_DIR, "productivity_log.json")
HEATMAP_IMAGE_PATH = os.path.join(OUTPUT_DIR, "productivity_heatmap.png")
HEATMAP_CSV_PATH = os.path.join(OUTPUT_DIR, "productivity_data.csv")

os.makedirs(OUTPUT_DIR, exist_ok=True)

# ============================================================================
# PRODUCTIVITY TRACKER
# ============================================================================

class ProductivityTracker:
    """Tracks user productivity patterns over time"""
    
    def __init__(self, log_path: str = PRODUCTIVITY_LOG_PATH):
        self.log_path = log_path
        self.data = self._load_log()
    
    def _load_log(self) -> List[Dict]:
        """Load productivity log from disk"""
        if os.path.exists(self.log_path):
            try:
                with open(self.log_path, 'r') as f:
                    return json.load(f)
            except Exception as e:
                print(f"[ERROR] Could not load productivity log: {e}")
                return []
        return []
    
    def _save_log(self):
        """Save productivity log to disk"""
        try:
            with open(self.log_path, 'w') as f:
                json.dump(self.data[-10000:], f, indent=2, default=str)  # Keep last 10k entries
        except Exception as e:
            print(f"[ERROR] Could not save productivity log: {e}")
    
    def record_task_completed(
        self,
        task_name: str,
        day: str,
        start_time: time,
        end_time: time,
        category: str = "general",
        estimated_duration: float = None,
        actual_duration: float = None,
        quality_rating: int = None
    ):
        """
        Record a completed task
        
        Args:
            task_name: Name of task
            day: Day of week
            start_time: Start time
            end_time: End time
            category: Task category (study, work, personal)
            estimated_duration: Estimated hours
            actual_duration: Actual hours spent
            quality_rating: 1-5 quality rating (optional)
        """
        entry = {
            "timestamp": datetime.now().isoformat(),
            "task_name": task_name,
            "day": day,
            "start_time": start_time.strftime("%H:%M") if isinstance(start_time, time) else start_time,
            "end_time": end_time.strftime("%H:%M") if isinstance(end_time, time) else end_time,
            "start_hour": start_time.hour if isinstance(start_time, time) else int(start_time.split(":")[0]),
            "category": category,
            "estimated_duration": estimated_duration,
            "actual_duration": actual_duration,
            "quality_rating": quality_rating,
            "completion_status": "completed"
        }
        
        self.data.append(entry)
        self._save_log()
        
        print(f"[PRODUCTIVITY] Recorded: {task_name} on {day} at {entry['start_time']}")
        
        return entry
    
    def record_task_skipped(
        self,
        task_name: str,
        scheduled_day: str,
        scheduled_time: time,
        reason: str = ""
    ):
        """Record a task that was skipped/not completed"""
        entry = {
            "timestamp": datetime.now().isoformat(),
            "task_name": task_name,
            "day": scheduled_day,
            "start_time": scheduled_time.strftime("%H:%M") if isinstance(scheduled_time, time) else scheduled_time,
            "start_hour": scheduled_time.hour if isinstance(scheduled_time, time) else int(scheduled_time.split(":")[0]),
            "completion_status": "skipped",
            "reason": reason
        }
        
        self.data.append(entry)
        self._save_log()
        
        print(f"[PRODUCTIVITY] Recorded skip: {task_name} on {scheduled_day}")
        
        return entry
    
    def get_completion_rate(self, category: str = None, days: int = 30) -> float:
        """
        Calculate task completion rate
        
        Args:
            category: Optional category filter
            days: Number of past days to analyze
        
        Returns:
            Completion rate as percentage (0-100)
        """
        cutoff = datetime.now() - timedelta(days=days)
        
        relevant = [
            d for d in self.data
            if datetime.fromisoformat(d["timestamp"]) > cutoff
            and (category is None or d.get("category") == category)
        ]
        
        if not relevant:
            return 0.0
        
        completed = sum(1 for d in relevant if d["completion_status"] == "completed")
        return (completed / len(relevant)) * 100.0
    
    def get_hourly_productivity(self, normalize: bool = True) -> Dict[int, float]:
        """
        Get productivity score by hour of day
        
        Args:
            normalize: Normalize to 0-1 range
        
        Returns:
            Dictionary: hour (0-23) -> productivity_score (0-1)
        """
        hourly_scores = {h: [] for h in range(24)}
        
        for entry in self.data:
            if entry["completion_status"] == "completed":
                hour = entry["start_hour"]
                quality = entry.get("quality_rating", 3) / 5.0  # Default to middle
                hourly_scores[hour].append(quality)
        
        # Average scores for each hour
        hourly_avg = {}
        for hour, scores in hourly_scores.items():
            if scores:
                hourly_avg[hour] = np.mean(scores)
            else:
                hourly_avg[hour] = 0.0
        
        if normalize:
            max_score = max(hourly_avg.values()) or 1.0
            if max_score > 0:
                hourly_avg = {h: s / max_score for h, s in hourly_avg.items()}
        
        return hourly_avg
    
    def get_daily_productivity(self, normalize: bool = True) -> Dict[str, float]:
        """
        Get productivity score by day of week
        
        Returns:
            Dictionary: day -> productivity_score (0-1)
        """
        days_scores = {
            "Monday": [], "Tuesday": [], "Wednesday": [],
            "Thursday": [], "Friday": [], "Saturday": [], "Sunday": []
        }
        
        for entry in self.data:
            if entry["completion_status"] == "completed":
                day = entry["day"]
                quality = entry.get("quality_rating", 3) / 5.0
                if day in days_scores:
                    days_scores[day].append(quality)
        
        # Average scores for each day
        daily_avg = {}
        for day, scores in days_scores.items():
            if scores:
                daily_avg[day] = np.mean(scores)
            else:
                daily_avg[day] = 0.0
        
        if normalize:
            max_score = max(daily_avg.values()) or 1.0
            if max_score > 0:
                daily_avg = {d: s / max_score for d, s in daily_avg.items()}
        
        return daily_avg
    
    def get_productivity_heatmap_data(self) -> np.ndarray:
        """
        Generate 2D heatmap: days × hours
        
        Returns:
            numpy array: (7 days × 24 hours)
        """
        # Initialize grid
        days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
        heatmap = np.zeros((7, 24))
        
        # Count completions per day/hour
        for entry in self.data:
            if entry["completion_status"] == "completed":
                day_idx = days.index(entry["day"]) if entry["day"] in days else -1
                if day_idx >= 0:
                    hour = entry["start_hour"]
                    quality = entry.get("quality_rating", 3) / 5.0
                    heatmap[day_idx, hour] += quality
        
        # Normalize
        max_val = np.max(heatmap) or 1.0
        heatmap = heatmap / max_val
        
        return heatmap


# ============================================================================
# VISUALIZATION
# ============================================================================

def generate_heatmap_image(tracker: ProductivityTracker) -> bool:
    """
    Generate and save heatmap visualization
    
    Returns:
        True if successful
    """
    if not MATPLOTLIB_AVAILABLE:
        print("[WARNING] matplotlib not available. Skipping image generation.")
        return False
    
    heatmap_data = tracker.get_productivity_heatmap_data()
    
    days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
    hours = [f"{h:02d}:00" for h in range(24)]
    
    # Create figure
    fig, ax = plt.subplots(figsize=(16, 6))
    
    # Plot heatmap
    im = ax.imshow(heatmap_data, cmap='YlOrRd', aspect='auto')
    
    # Set labels
    ax.set_xticks(range(24))
    ax.set_xticklabels(hours, rotation=45, ha='right')
    ax.set_yticks(range(7))
    ax.set_yticklabels(days)
    
    # Labels and title
    ax.set_xlabel("Hour of Day", fontsize=12, fontweight='bold')
    ax.set_ylabel("Day of Week", fontsize=12, fontweight='bold')
    ax.set_title("User Productivity Heatmap (Darker = Higher Productivity)", fontsize=14, fontweight='bold')
    
    # Add colorbar
    cbar = plt.colorbar(im, ax=ax)
    cbar.set_label("Productivity Score", rotation=270, labelpad=20)
    
    # Add values to cells
    for i in range(7):
        for j in range(24):
            text = ax.text(j, i, f'{heatmap_data[i, j]:.2f}',
                          ha="center", va="center", color="black", fontsize=7)
    
    plt.tight_layout()
    plt.savefig(HEATMAP_IMAGE_PATH, dpi=100, bbox_inches='tight')
    print(f"✓ Heatmap image saved: {HEATMAP_IMAGE_PATH}")
    
    plt.close()
    return True


def generate_heatmap_text(tracker: ProductivityTracker) -> str:
    """Generate text-based heatmap for console display"""
    
    heatmap_data = tracker.get_productivity_heatmap_data()
    
    days = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]
    
    # Create text heatmap
    lines = []
    lines.append("\nPRODUCTIVITY HEATMAP (Text View)")
    lines.append("=" * 100)
    lines.append("Hour:  " + "  ".join(f"{h:2d}" for h in range(24)))
    lines.append("-" * 100)
    
    for day_idx, day in enumerate(days):
        row = f"{day:3s}:  "
        for hour_idx in range(24):
            score = heatmap_data[day_idx, hour_idx]
            if score >= 0.8:
                char = "█"  # Full block
            elif score >= 0.6:
                char = "▓"  # Dark shade
            elif score >= 0.4:
                char = "▒"  # Medium shade
            elif score >= 0.2:
                char = "░"  # Light shade
            else:
                char = " "  # Empty
            row += f" {char} "
        lines.append(row)
    
    lines.append("-" * 100)
    lines.append("Legend: █ (Highest) ▓ ▒ ░ (Lowest)")
    lines.append("=" * 100)
    
    return "\n".join(lines)


def generate_heatmap_csv(tracker: ProductivityTracker):
    """Export heatmap data as CSV"""
    
    heatmap_data = tracker.get_productivity_heatmap_data()
    days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
    
    with open(HEATMAP_CSV_PATH, 'w', newline='') as f:
        writer = csv.writer(f)
        
        # Header
        writer.writerow(["Hour"] + days)
        
        # Data rows
        for hour in range(24):
            row = [f"{hour:02d}:00"]
            for day_idx in range(7):
                row.append(heatmap_data[day_idx, hour])
            writer.writerow(row)
    
    print(f"✓ Heatmap CSV saved: {HEATMAP_CSV_PATH}")


# ============================================================================
# REPORTING
# ============================================================================

def generate_productivity_report(tracker: ProductivityTracker) -> Dict:
    """
    Generate comprehensive productivity report
    
    Returns:
        Dictionary with all productivity metrics
    """
    hourly = tracker.get_hourly_productivity()
    daily = tracker.get_daily_productivity()
    completion_rate = tracker.get_completion_rate()
    
    # Find peak hours
    peak_hours = sorted(hourly.items(), key=lambda x: x[1], reverse=True)[:3]
    peak_days = sorted(daily.items(), key=lambda x: x[1], reverse=True)[:3]
    
    report = {
        "timestamp": datetime.now().isoformat(),
        "total_tasks": len(tracker.data),
        "completion_rate_30d": completion_rate,
        "peak_productive_hours": [
            {"hour": f"{h:02d}:00", "score": float(s)} for h, s in peak_hours
        ],
        "peak_productive_days": [
            {"day": d, "score": float(s)} for d, s in peak_days
        ],
        "hourly_scores": {f"{h:02d}:00": float(s) for h, s in hourly.items()},
        "daily_scores": daily
    }
    
    return report


def print_productivity_summary(tracker: ProductivityTracker):
    """Print summary of productivity metrics"""
    
    report = generate_productivity_report(tracker)
    
    print("\n" + "="*70)
    print("PRODUCTIVITY ANALYSIS REPORT")
    print("="*70)
    print(f"Total Tasks Logged: {report['total_tasks']}")
    print(f"Completion Rate (30d): {report['completion_rate_30d']:.1f}%")
    print()
    print("Peak Productive Hours:")
    for item in report['peak_productive_hours']:
        print(f"  • {item['hour']}: {item['score']:.2f}")
    print()
    print("Peak Productive Days:")
    for item in report['peak_productive_days']:
        print(f"  • {item['day']}: {item['score']:.2f}")
    print("="*70 + "\n")


# ============================================================================
# MAIN INTERFACE
# ============================================================================

def get_tracker() -> ProductivityTracker:
    """Get global productivity tracker instance"""
    return ProductivityTracker()


def generate_all_heatmap_outputs(tracker: ProductivityTracker = None):
    """Generate all heatmap outputs (image, CSV, text)"""
    
    if tracker is None:
        tracker = get_tracker()
    
    print("\n" + "="*70)
    print("GENERATING PRODUCTIVITY HEATMAP REPORT")
    print("="*70 + "\n")
    
    # Text heatmap
    print(generate_heatmap_text(tracker))
    
    # CSV export
    generate_heatmap_csv(tracker)
    
    # Image heatmap (if matplotlib available)
    if MATPLOTLIB_AVAILABLE:
        generate_heatmap_image(tracker)
    
    # Summary report
    print_productivity_summary(tracker)
    
    print("✅ Heatmap generation complete!")


# ============================================================================
# MAIN
# ============================================================================

if __name__ == "__main__":
    print("Testing Productivity Heatmap Module...")
    
    tracker = ProductivityTracker()
    
    # Simulate some tracked data
    print("\nSimulating productivity data...")
    tracker.record_task_completed("Study session", "Monday", time(9, 0), time(11, 0), quality_rating=5)
    tracker.record_task_completed("Work on project", "Tuesday", time(14, 0), time(16, 0), quality_rating=4)
    tracker.record_task_completed("Exercise", "Wednesday", time(17, 0), time(18, 0), quality_rating=3)
    tracker.record_task_completed("Reading", "Thursday", time(10, 0), time(11, 30), quality_rating=5)
    tracker.record_task_skipped("Research", "Friday", time(15, 0), reason="Ran out of time")
    
    # Generate reports
    generate_all_heatmap_outputs(tracker)
    
    print("✅ Productivity Heatmap test complete!")
