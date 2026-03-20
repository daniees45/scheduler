# data_pipeline.py
"""
Data pipeline for loading historical schedule data from B2 cloud for ensemble model training.
"""

import os
import pandas as pd
from b2_handler import B2Handler

B2_HISTORICAL_KEY = "csv/general/historical_data.csv"
B2_EXAM_KEY = "csv/general/historical_exam_schedule.csv"
LOCAL_DATA_PATH = "temp/historical_data.csv"
LOCAL_EXAM_PATH = "temp/historical_exam_schedule.csv"

def download_historical_data():
    b2 = B2Handler(enable_cache=True, cache_dir="temp/b2_cache")
    ok1 = b2.download_file(B2_HISTORICAL_KEY, LOCAL_DATA_PATH)
    ok2 = b2.download_file(B2_EXAM_KEY, LOCAL_EXAM_PATH)
    return ok1 and ok2

def load_historical_data():
    if not os.path.exists(LOCAL_DATA_PATH) or not os.path.exists(LOCAL_EXAM_PATH):
        download_historical_data()
    df_sched = pd.read_csv(LOCAL_DATA_PATH)
    df_exam = pd.read_csv(LOCAL_EXAM_PATH)
    return df_sched, df_exam
