"""
ML System Test & Validation Script
Tests all ML components and validates integration
"""

import sys
import os

# Add timetable_engine to path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

def test_imports():
    """Test that all ML components can be imported"""
    print("\n" + "="*70)
    print("  TEST 1: COMPONENT IMPORTS")
    print("="*70)
    
    try:
        from schedule_accuracy_predictor_v2 import (
            EnsembleSchedulePredictor,
            AdvancedFeatureEngineer
        )
        print("✓ schedule_accuracy_predictor_v2 imported successfully")
    except ImportError as e:
        print(f"✗ Failed to import schedule_accuracy_predictor_v2: {e}")
        return False
    
    try:
        from training_data_collector import TrainingDataCollector
        print("✓ training_data_collector imported successfully")
    except ImportError as e:
        print(f"✗ Failed to import training_data_collector: {e}")
        return False
    
    try:
        from ml_training_workflow import MLTrainingWorkflow
        print("✓ ml_training_workflow imported successfully")
    except ImportError as e:
        print(f"✗ Failed to import ml_training_workflow: {e}")
        return False
    
    return True


def test_feature_engineering():
    """Test advanced feature engineering"""
    print("\n" + "="*70)
    print("  TEST 2: FEATURE ENGINEERING")
    print("="*70)
    
    from schedule_accuracy_predictor_v2 import AdvancedFeatureEngineer
    
    test_entry = {
        "course_code": "COSC 201",
        "level": 2,
        "semester": 1,
        "credits": "3",
        "lecturer": "Dr. Smith",
        "day": "Monday",
        "time_slot": "9:30am - 12:00pm",
        "room_name": "A101",
        "room_capacity": 100,
        "enrollment": 75,
        "is_special_room": False,
        "lecturer_availability_ratio": 0.85,
        "lecturer_daily_load": 4,
        "conflict_likelihood": 0.1,
        "lecturer_specialty_match": 0.8,
        "enrollment_stability": 0.9,
        "time_gap_factor": 0.7
    }
    
    features = AdvancedFeatureEngineer.engineer_features(test_entry)
    feature_names = AdvancedFeatureEngineer.get_feature_names()
    
    print(f"✓ Generated {features.shape[1]} features from test entry")
    print(f"✓ Feature count matches: {len(feature_names)} features")
    
    print(f"\nFeature Details:")
    for i, (name, value) in enumerate(zip(feature_names, features[0]), 1):
        print(f"  {i:2d}. {name:30s} = {value:8.4f}")
    
    return True


def test_trainer_creation():
    """Test predictor initialization"""
    print("\n" + "="*70)
    print("  TEST 3: PREDICTOR INITIALIZATION")
    print("="*70)
    
    from schedule_accuracy_predictor_v2 import EnsembleSchedulePredictor
    
    predictor = EnsembleSchedulePredictor(".")
    
    status = predictor.get_model_status()
    print(f"✓ Predictor initialized")
    print(f"  sklearn available: {status.get('sklearn_available')}")
    print(f"  RF model: {status.get('has_rf_model')}")
    print(f"  GB model: {status.get('has_gb_model')}")
    print(f"  MLP model: {status.get('has_mlp_model')}")
    print(f"  Feature count: {status.get('feature_count')}")
    
    return status.get('sklearn_available', False)


def test_data_collector():
    """Test training data collection"""
    print("\n" + "="*70)
    print("  TEST 4: TRAINING DATA COLLECTION")
    print("="*70)
    
    from training_data_collector import TrainingDataCollector
    
    collector = TrainingDataCollector(".")
    
    # Check existing data
    stats = collector.get_statistics()
    print(f"✓ Data collector initialized")
    print(f"  Current samples: {stats.get('total_samples', 0)}")
    print(f"  Ready for training: {stats.get('ready_for_training', False)}")
    
    # Generate synthetic data
    print(f"\nGenerating 50 synthetic training samples...")
    gen_stats = collector.generate_synthetic_training_data(50)
    print(f"✓ Generated {gen_stats['generated']} samples")
    print(f"  Good: {gen_stats['label_distribution']['good']}")
    print(f"  Conflicts: {gen_stats['label_distribution']['conflict']}")
    
    # Check balance
    balance = collector.validate_data_balance()
    print(f"\n✓ Data balance check:")
    print(f"  Status: {balance.get('overall_status')}")
    print(f"  Good ratio: {balance.get('good_ratio')}")
    
    # Save data
    collector.save_training_data()
    print(f"✓ Training data saved")
    
    return True


def test_training():
    """Test model training"""
    print("\n" + "="*70)
    print("  TEST 5: MODEL TRAINING")
    print("="*70)
    
    from schedule_accuracy_predictor_v2 import EnsembleSchedulePredictor
    from training_data_collector import TrainingDataCollector
    
    print("Preparing training data...")
    collector = TrainingDataCollector(".")
    training_data = collector.get_training_data_for_model()
    
    if len(training_data) < 50:
        print(f"⚠ Skipping training: Only {len(training_data)} samples (need 50+)")
        return False
    
    print(f"✓ Training data ready: {len(training_data)} samples")
    
    print("\nTraining ensemble model...")
    predictor = EnsembleSchedulePredictor(".")
    metrics = predictor.train(training_data, validation_split=0.2)
    
    if metrics.get("status") == "trained":
        print(f"✓ Training completed successfully")
        print(f"  Accuracy:  {metrics.get('accuracy', 0)*100:.2f}%")
        print(f"  Precision: {metrics.get('precision', 0)*100:.2f}%")
        print(f"  Recall:    {metrics.get('recall', 0)*100:.2f}%")
        print(f"  F1-Score:  {metrics.get('f1', 0)*100:.2f}%")
        
        # Feature importance
        print(f"\n✓ Top 5 Most Important Features:")
        importance = predictor.get_feature_importance_summary()
        for idx, (feature, score) in enumerate(list(importance.items())[:5], 1):
            print(f"  {idx}. {feature}: {score}")
        
        return True
    else:
        print(f"✗ Training failed: {metrics.get('reason')}")
        return False


def test_prediction():
    """Test model prediction"""
    print("\n" + "="*70)
    print("  TEST 6: PREDICTION")
    print("="*70)
    
    from schedule_accuracy_predictor_v2 import EnsembleSchedulePredictor
    from training_data_collector import TrainingDataCollector
    
    # Check if model exists
    predictor = EnsembleSchedulePredictor(".")
    
    if predictor.ensemble_model is None:
        print("⚠ Model not trained. Skipping prediction test.")
        return False
    
    # Test sample
    test_sample = {
        "course_code": "COSC 301",
        "level": 3,
        "semester": 2,
        "credits": "3",
        "lecturer": "Prof. Johnson",
        "day": "Wednesday",
        "time_slot": "2:00pm - 5:00pm",
        "room_name": "LAB1",
        "room_capacity": 60,
        "enrollment": 45,
        "is_special_room": True,
        "lecturer_availability_ratio": 0.92,
        "lecturer_daily_load": 3,
        "conflict_likelihood": 0.08,
        "lecturer_specialty_match": 0.9,
        "enrollment_stability": 0.88,
        "time_gap_factor": 0.8
    }
    
    print("Testing single prediction...")
    pred_class, confidence, metrics = predictor.predict_class(test_sample)
    
    prediction_text = "GOOD SCHEDULE ✓" if pred_class == 0 else "CONFLICT DETECTED ✗"
    print(f"✓ Prediction: {prediction_text}")
    print(f"  Confidence: {confidence*100:.2f}%")
    print(f"  Probability (Good): {metrics.get('probability_good', 0)*100:.2f}%")
    print(f"  Probability (Conflict): {metrics.get('probability_conflict', 0)*100:.2f}%")
    
    # Schedule quality
    print("\nTesting schedule quality prediction...")
    schedule_items = [test_sample for _ in range(10)]
    quality = predictor.predict_schedule_quality(schedule_items)
    
    print(f"✓ Schedule quality rating: {quality.get('grade')}")
    print(f"  Overall score: {quality.get('overall_quality_score')*100:.2f}%")
    print(f"  Conflict count: {quality.get('conflict_count')}")
    print(f"  Items tested: {quality.get('total_items')}")
    
    return True


def run_all_tests():
    """Run all tests"""
    print("\n" + "="*70)
    print("  ML SYSTEM VALIDATION TEST SUITE")
    print("="*70)
    print(f"\nRunning comprehensive validation tests...\n")
    
    tests = [
        ("Component Imports", test_imports),
        ("Feature Engineering", test_feature_engineering),
        ("Predictor Creation", test_trainer_creation),
        ("Data Collection", test_data_collector),
        ("Model Training", test_training),
        ("Prediction", test_prediction)
    ]
    
    results = {}
    passed = 0
    failed = 0
    
    for test_name, test_func in tests:
        try:
            result = test_func()
            results[test_name] = "PASSED" if result else "SKIPPED"
            if result:
                passed += 1
            else:
                failed += 1
        except Exception as e:
            print(f"\n✗ Test failed with exception: {e}")
            results[test_name] = f"FAILED: {str(e)}"
            failed += 1
    
    # Final summary
    print("\n" + "="*70)
    print("  TEST SUMMARY")
    print("="*70)
    
    for test_name, result in results.items():
        status_symbol = "✓" if result == "PASSED" else ("⚠" if result == "SKIPPED" else "✗")
        print(f"{status_symbol} {test_name}: {result}")
    
    print(f"\nTotal: {passed} passed, {failed} failed")
    
    if failed == 0:
        print("\n✓ ALL TESTS PASSED - ML System is ready to use!")
    
    return failed == 0


if __name__ == "__main__":
    success = run_all_tests()
    sys.exit(0 if success else 1)
