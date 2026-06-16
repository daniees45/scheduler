#!/usr/bin/env python3
"""
Neural Network Model Training Script
Explicitly train and save NN models for schedule generation
"""

import sys
import os
import json
import argparse
import pandas as pd
import numpy as np
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple

from timetable_engine.neural_network_scheduler import NeuralNetworkScheduler


class NNModelTrainer:
    """Train and evaluate Neural Network models for scheduling"""
    
    def __init__(self, data_path: str = ".", verbose: bool = True):
        self.data_path = data_path
        self.verbose = verbose
        self.model_dir = Path(data_path) / "models" / "nn"
        self.model_dir.mkdir(parents=True, exist_ok=True)
        
    def load_training_data(self, csv_path: str = None) -> Tuple[pd.DataFrame, Dict]:
        """Load historical schedule data for training"""
        if csv_path is None:
            csv_path = os.path.join(self.data_path, "csv/general", "historical_schedule.csv")
        
        if not os.path.exists(csv_path):
            print(f"❌ Training data not found: {csv_path}")
            print("   Generate some schedules first to create training data.")
            return None, None
        
        df = pd.read_csv(csv_path)
        if self.verbose:
            print(f"✓ Loaded {len(df)} historical schedule records")
            print(f"   Columns: {', '.join(df.columns[:5])}...")
        
        # Extract metadata
        metadata = {
            'num_records': len(df),
            'unique_courses': df['course_code'].nunique() if 'course_code' in df.columns else 0,
            'unique_lecturers': df['lecturer'].nunique() if 'lecturer' in df.columns else 0,
            'unique_rooms': df['room'].nunique() if 'room' in df.columns else 0,
            'date_range': f"{df['timestamp'].min()} to {df['timestamp'].max()}" if 'timestamp' in df.columns else 'unknown'
        }
        
        return df, metadata
    
    def extract_entities_from_data(self, df: pd.DataFrame) -> Tuple[List, List, List, List, List]:
        """Extract courses, lecturers, rooms, time_slots, days from historical data"""
        # Normalize column names (handle different formats)
        column_mapping = {
            'Course Code': 'course_code',
            'Course Title': 'course_title',
            'Lecturer Name': 'lecturer',
            'Room Name': 'room',
            'Day': 'day',
            'Time': 'time',
            'course_level': 'level',
            'Semester': 'semester',
            'Credit Hrs': 'credits',
            'enrollment': 'enrollment',
            'no_of_students': 'enrollment'
        }
        df = df.rename(columns=column_mapping)
        
        # Extract unique entities
        courses = []
        if 'course_code' in df.columns:
            for code in df['course_code'].unique():
                course_data = df[df['course_code'] == code].iloc[0]
                courses.append({
                    'code': code,
                    'title': course_data.get('course_title', code),
                    'level': course_data.get('level', 100),
                    'semester': course_data.get('semester', 1),
                    'credits': course_data.get('credits', 3),
                    'enrollment': course_data.get('enrollment', 30),
                    'lecturer': course_data.get('lecturer', '')
                })
        
        lecturers = df['lecturer'].unique().tolist() if 'lecturer' in df.columns else []
        
        rooms = []
        if 'room' in df.columns:
            for room_name in df['room'].unique():
                room_data = df[df['room'] == room_name].iloc[0]
                rooms.append({
                    'name': room_name,
                    'capacity': room_data.get('capacity', 40),
                    'type': room_data.get('room_type', 'Lecture')
                })
        
        time_slots = df['time'].unique().tolist() if 'time' in df.columns else [
            "07:00 AM - 09:30 AM", "10:00 AM - 12:30 PM", "02:00 PM - 04:30 PM"
        ]
        
        days = df['day'].unique().tolist() if 'day' in df.columns else [
            "Monday", "Tuesday", "Wednesday", "Thursday", "Friday"
        ]
        
        if self.verbose:
            print(f"   Courses: {len(courses)}, Lecturers: {len(lecturers)}, Rooms: {len(rooms)}")
            print(f"   Time slots: {len(time_slots)}, Days: {len(days)}")
        
        return courses, lecturers, rooms, time_slots, days
    
    def prepare_training_samples(self, df: pd.DataFrame, scheduler: NeuralNetworkScheduler,
                                num_samples: int = 500) -> Tuple[List, np.ndarray]:
        """Prepare training samples from historical data"""
        # Normalize column names
        column_mapping = {
            'Course Code': 'course_code',
            'Course Title': 'course_title',
            'Lecturer Name': 'lecturer',
            'Room Name': 'room',
            'Day': 'day',
            'Time': 'time',
            'course_level': 'level',
            'Semester': 'semester',
            'Credit Hrs': 'credits',
            'enrollment': 'enrollment',
            'no_of_students': 'enrollment'
        }
        df = df.rename(columns=column_mapping)
        
        num_context_features = scheduler.num_context_features
        
        # Use actual data size
        num_samples = min(num_samples, len(df))
        num_negatives = min(100, num_samples // 3)
        
        # Pre-allocate arrays
        course_indices = []
        lecturer_indices = []
        room_indices = []
        slot_indices = []
        context_features_list = []
        
        # Positive samples from historical data
        for idx, row in df.head(num_samples).iterrows():
            # Map entities to indices
            course_code = row.get('course_code', '')
            course_idx = next((i for i, c in enumerate(scheduler.courses) if c.get('code') == course_code), 0)
            
            lecturer = row.get('lecturer', '')
            lecturer_idx = scheduler.lecturers.index(lecturer) if lecturer in scheduler.lecturers else 0
            
            room_name = row.get('room', '')
            room_idx = next((i for i, r in enumerate(scheduler.rooms) if r.get('name') == room_name), 0)
            
            day = row.get('day', 'Monday')
            time = row.get('time', scheduler.time_slots[0] if scheduler.time_slots else '')
            day_idx = scheduler.days.index(day) if day in scheduler.days else 0
            time_idx = scheduler.time_slots.index(time) if time in scheduler.time_slots else 0
            slot_idx = day_idx * len(scheduler.time_slots) + time_idx
            
            course_indices.append(course_idx)
            lecturer_indices.append(lecturer_idx)
            room_indices.append(room_idx)
            slot_indices.append(slot_idx)
            
            # Context features from actual data
            context_feat = np.zeros(num_context_features, dtype=np.float32)
            context_feat[0] = row.get('room_utilization', np.random.uniform(0.3, 0.7))
            context_feat[1] = row.get('lecturer_load', np.random.uniform(0.2, 0.6))
            context_feat[2] = row.get('conflicts', 0.0) / 100.0
            context_feat[3] = 1.0 if row.get('capacity_match', True) else 0.5
            context_feat[4] = row.get('diversity_score', 0.7)
            # Violations remain zeros for successful assignments
            context_features_list.append(context_feat)
        
        # Negative samples (invalid assignments)
        for _ in range(num_negatives):
            course_indices.append(np.random.randint(0, max(1, len(scheduler.courses))))
            lecturer_indices.append(np.random.randint(0, max(1, len(scheduler.lecturers))))
            room_indices.append(np.random.randint(0, max(1, len(scheduler.rooms))))
            slot_indices.append(np.random.randint(0, max(1, scheduler.num_slots)))
            
            neg_features = np.random.rand(num_context_features).astype(np.float32)
            if num_context_features >= 8:
                neg_features[5:8] = np.random.rand(3)  # Add violations
            context_features_list.append(neg_features)
        
        # Convert to numpy arrays
        course_indices = np.array(course_indices, dtype=np.int32)
        lecturer_indices = np.array(lecturer_indices, dtype=np.int32)
        room_indices = np.array(room_indices, dtype=np.int32)
        slot_indices = np.array(slot_indices, dtype=np.int32)
        context_features_arr = np.array(context_features_list, dtype=np.float32)
        
        # Labels
        y_train = np.array([1.0] * num_samples + [0.0] * num_negatives, dtype=np.float32)
        
        # Format for TensorFlow (list of arrays) or sklearn (list of tuples)
        try:
            import tensorflow as tf
            training_data = [course_indices, lecturer_indices, room_indices, slot_indices, context_features_arr]
        except ImportError:
            training_data = [
                (np.array([course_indices[i]]), np.array([lecturer_indices[i]]), 
                 np.array([room_indices[i]]), np.array([slot_indices[i]]), 
                 context_features_arr[i])
                for i in range(len(y_train))
            ]
        
        if self.verbose:
            print(f"✓ Prepared {len(y_train)} training samples ({num_samples} positive, {num_negatives} negative)")
        
        return training_data, y_train
    
    def train_model(self, historical_csv: str = None, epochs: int = None, 
                   embedding_dim: int = 32, hidden_dim: int = 128) -> Dict:
        """Train a new NN model from historical data"""
        print("\n" + "="*70)
        print("NEURAL NETWORK MODEL TRAINING")
        print("="*70)
        
        # Load training data
        print("\n1. Loading training data...")
        df, metadata = self.load_training_data(historical_csv)
        if df is None:
            return None
        
        # Extract entities
        print("\n2. Extracting entities from data...")
        courses, lecturers, rooms, time_slots, days = self.extract_entities_from_data(df)
        
        if not courses or not lecturers or not rooms:
            print("❌ Insufficient data to train model")
            return None
        
        # Initialize scheduler
        print("\n3. Initializing Neural Network Scheduler...")
        scheduler = NeuralNetworkScheduler(
            courses=courses,
            lecturers=lecturers,
            rooms=rooms,
            time_slots=time_slots,
            days=days,
            embedding_dim=embedding_dim,
            hidden_dim=hidden_dim,
            epochs=epochs if epochs else 50,
            verbose=self.verbose
        )
        
        print(f"   ✓ Model architecture: {embedding_dim}D embeddings, {hidden_dim}D hidden")
        
        # Prepare training samples
        print("\n4. Preparing training samples...")
        training_data, y_train = self.prepare_training_samples(df, scheduler)
        
        # Train model
        print("\n5. Training model...")
        training_stats = scheduler.train(training_data, y_train)
        
        print(f"\n   ✓ Training complete!")
        print(f"   ✓ Final accuracy: {training_stats.get('final_accuracy', 0):.2%}")
        print(f"   ✓ Final loss: {training_stats.get('final_loss', 0):.4f}")
        print(f"   ✓ Epochs trained: {training_stats.get('epochs_trained', 'N/A')}")
        
        # Save model
        print("\n6. Saving trained model...")
        model_info = self.save_model(scheduler, training_stats, metadata)
        
        return {
            'scheduler': scheduler,
            'training_stats': training_stats,
            'metadata': metadata,
            'model_info': model_info
        }
    
    def save_model(self, scheduler: NeuralNetworkScheduler, training_stats: Dict, 
                   data_metadata: Dict) -> Dict:
        """Save trained model to disk"""
        # Use fixed filename instead of timestamp
        model_name = "nn_scheduler"
        model_path = self.model_dir / model_name
        
        # Use scheduler's save method (includes metadata)
        try:
            import tensorflow as tf
            model_file = model_path.with_suffix('.h5')
            scheduler.save_model(str(model_file))
            backend = 'tensorflow'
        except:
            model_file = model_path.with_suffix('.pkl')
            scheduler.save_model(str(model_file))
            backend = 'sklearn'
        
        # Save additional training metadata
        from datetime import datetime
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        metadata = {
            'model_name': model_name,
            'last_trained': timestamp,
            'backend': backend,
            'model_file': str(model_file),
            'training_stats': training_stats,
            'data_metadata': data_metadata,
            'architecture': {
                'embedding_dim': scheduler.embedding_dim,
                'hidden_dim': scheduler.hidden_dim,
                'num_courses': scheduler.num_courses,
                'num_lecturers': scheduler.num_lecturers,
                'num_rooms': scheduler.num_rooms,
                'num_slots': scheduler.num_slots,
                'num_context_features': scheduler.num_context_features
            }
        }
        
        metadata_file = model_path.with_suffix('.json')
        with open(metadata_file, 'w') as f:
            json.dump(metadata, f, indent=2)
        
        print(f"   ✓ Model saved: {model_file}")
        print(f"   ✓ Metadata saved: {metadata_file}")
        
        return metadata
    
    def list_models(self) -> List[Dict]:
        """List all trained models"""
        models = []
        for metadata_file in self.model_dir.glob("*.json"):
            try:
                with open(metadata_file) as f:
                    metadata = json.load(f)
                models.append(metadata)
            except:
                pass
        
        return sorted(models, key=lambda x: x.get('timestamp', ''), reverse=True)
    
    def evaluate_model(self, model_path: str) -> Dict:
        """Evaluate a trained model on test data"""
        # Load model metadata
        metadata_file = Path(model_path).with_suffix('.json')
        with open(metadata_file) as f:
            metadata = json.load(f)
        
        print(f"\nEvaluating model: {metadata['model_name']}")
        print(f"Trained: {metadata['timestamp']}")
        print(f"Backend: {metadata['backend']}")
        print(f"Training accuracy: {metadata['training_stats']['final_accuracy']:.2%}")
        
        return metadata


def main():
    parser = argparse.ArgumentParser(description="Train Neural Network models for schedule generation")
    parser.add_argument('--data', type=str, default=".", help="Data directory path")
    parser.add_argument('--csv', type=str, help="Historical CSV file path")
    parser.add_argument('--epochs', type=int, help="Number of training epochs")
    parser.add_argument('--embedding-dim', type=int, default=32, help="Embedding dimension")
    parser.add_argument('--hidden-dim', type=int, default=128, help="Hidden layer dimension")
    parser.add_argument('--list', action='store_true', help="List all trained models")
    parser.add_argument('--evaluate', type=str, help="Evaluate a specific model")
    parser.add_argument('--quiet', action='store_true', help="Reduce output verbosity")
    
    args = parser.parse_args()
    
    trainer = NNModelTrainer(data_path=args.data, verbose=not args.quiet)
    
    # List models
    if args.list:
        print("\n" + "="*70)
        print("TRAINED NEURAL NETWORK MODELS")
        print("="*70)
        models = trainer.list_models()
        if not models:
            print("\nNo trained models found.")
            print(f"Train a model first: python3 {sys.argv[0]}")
        else:
            for i, model in enumerate(models, 1):
                print(f"\n{i}. {model['model_name']}")
                print(f"   Trained: {model['timestamp']}")
                print(f"   Accuracy: {model['training_stats']['final_accuracy']:.2%}")
                print(f"   Backend: {model['backend']}")
                print(f"   Data: {model['data_metadata']['num_records']} records")
        return 0
    
    # Evaluate model
    if args.evaluate:
        trainer.evaluate_model(args.evaluate)
        return 0
    
    # Train new model
    result = trainer.train_model(
        historical_csv=args.csv,
        epochs=args.epochs,
        embedding_dim=args.embedding_dim,
        hidden_dim=args.hidden_dim
    )
    
    if result:
        print("\n" + "="*70)
        print("✓ MODEL TRAINING COMPLETE")
        print("="*70)
        print("\nModel saved and ready to use!")
        print(f"To use this model in your scheduler, reference:")
        print(f"  {result['model_info']['model_file']}")
        print("\nYou can now:")
        print("  • Load this model in the web interface")
        print("  • Use it via the Python API")
        print("  • Continue training with more data")
        return 0
    else:
        print("\n❌ Model training failed")
        return 1


if __name__ == "__main__":
    sys.exit(main())
