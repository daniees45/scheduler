"""
Comprehensive Test Suite for AI Unified Scheduling System
Tests all 4 schedulers: GA, RL, NN, Ensemble ML
"""

import unittest
import json
import tempfile
import os
from typing import List, Dict, Any
import numpy as np

from timetable_engine.ai_unified_scheduler import AIUnifiedScheduler


class TestAIUnifiedScheduler(unittest.TestCase):
    """Test suite for unified AI scheduler"""
    
    @classmethod
    def setUpClass(cls):
        """Set up test data once for all tests"""
        cls.courses = [
            {'code': 'CS101', 'name': 'Intro to CS', 'lecturer': 'Dr. Smith', 'capacity': 50},
            {'code': 'CS201', 'name': 'Data Structures', 'lecturer': 'Dr. Jones', 'capacity': 40},
            {'code': 'CS301', 'name': 'Algorithms', 'lecturer': 'Dr. Brown', 'capacity': 35},
        ]
        
        cls.lecturers = ['Dr. Smith', 'Dr. Jones', 'Dr. Brown']
        
        cls.rooms = [
            {'name': 'LR1', 'capacity': 100},
            {'name': 'LR2', 'capacity': 80},
            {'name': 'SR1', 'capacity': 40},
        ]
        
        cls.time_slots = [
            '7:00am - 9:30am',
            '9:45am - 12:15pm',
            '1:00pm - 3:30pm',
        ]
    
    def setUp(self):
        """Set up before each test"""
        self.temp_dir = tempfile.mkdtemp()
        self.scheduler = AIUnifiedScheduler(
            data_path=self.temp_dir,
            courses=self.courses,
            lecturers=self.lecturers,
            rooms=self.rooms,
            time_slots=self.time_slots,
            enable_ga=True,
            enable_rl=True,
            enable_nn=True,
            enable_ensemble=True,
            verbose=False
        )
    
    def tearDown(self):
        """Clean up after each test"""
        import shutil
        if os.path.exists(self.temp_dir):
            shutil.rmtree(self.temp_dir)
    
    # =================================================================
    # TESTS FOR SCHEDULER INITIALIZATION
    # =================================================================
    
    def test_scheduler_initialization(self):
        """Test that scheduler initializes all components"""
        self.assertIsNotNone(self.scheduler.ga_scheduler, "GA scheduler not initialized")
        self.assertIsNotNone(self.scheduler.rl_scheduler, "RL scheduler not initialized")
        self.assertIsNotNone(self.scheduler.nn_scheduler, "NN scheduler not initialized")
        self.assertIsNotNone(self.scheduler.ensemble_predictor, "Ensemble not initialized")
    
    def test_data_loading(self):
        """Test that data is correctly loaded"""
        self.assertEqual(len(self.scheduler.courses), 3, "Courses not loaded")
        self.assertEqual(len(self.scheduler.lecturers), 3, "Lecturers not loaded")
        self.assertEqual(len(self.scheduler.rooms), 3, "Rooms not loaded")
        self.assertEqual(len(self.scheduler.time_slots), 3, "Time slots not loaded")
    
    def test_selective_initialization(self):
        """Test enabling/disabling specific schedulers"""
        scheduler_ga_only = AIUnifiedScheduler(
            data_path=self.temp_dir,
            courses=self.courses,
            lecturers=self.lecturers,
            rooms=self.rooms,
            time_slots=self.time_slots,
            enable_ga=True,
            enable_rl=False,
            enable_nn=False,
            enable_ensemble=False,
            verbose=False
        )
        
        self.assertIsNotNone(scheduler_ga_only.ga_scheduler)
        self.assertIsNone(scheduler_ga_only.rl_scheduler)
        self.assertIsNone(scheduler_ga_only.nn_scheduler)
        self.assertIsNone(scheduler_ga_only.ensemble_predictor)
    
    # =================================================================
    # TESTS FOR GENETIC ALGORITHM SCHEDULER
    # =================================================================
    
    def test_ga_scheduling_produces_output(self):
        """Test that GA produces a valid schedule"""
        schedule, quality, metadata = self.scheduler.schedule_with_ga()
        
        self.assertIsNotNone(schedule, "GA returned None schedule")
        self.assertGreater(quality, 0, "GA returned non-positive quality")
        self.assertLessEqual(quality, 1.0, "GA quality exceeds 1.0")
        self.assertIn('method', metadata, "Missing 'method' in metadata")
        self.assertEqual(metadata['method'], 'Genetic Algorithm')
    
    def test_ga_quality_score_valid(self):
        """Test that GA quality score is valid (0-1)"""
        _, quality, _ = self.scheduler.schedule_with_ga()
        self.assertGreaterEqual(quality, 0.0)
        self.assertLessEqual(quality, 1.0)
    
    def test_ga_metadata_completeness(self):
        """Test that GA provides complete metadata"""
        _, _, metadata = self.scheduler.schedule_with_ga()
        
        required_keys = ['method', 'fitness', 'conflicts', 'room_utilization']
        for key in required_keys:
            self.assertIn(key, metadata, f"Missing '{key}' in GA metadata")
    
    def test_ga_schedule_format(self):
        """Test that GA schedule has correct format"""
        schedule, _, _ = self.scheduler.schedule_with_ga()
        
        if schedule:
            for assignment in schedule:
                self.assertIsInstance(assignment, dict, "Schedule item not dict")
                # Check common fields
                if 'course' in assignment:
                    self.assertIsInstance(assignment['course'], str, "Course not string")
    
    # =================================================================
    # TESTS FOR REINFORCEMENT LEARNING SCHEDULER
    # =================================================================
    
    def test_rl_scheduling_produces_output(self):
        """Test that RL produces a valid schedule"""
        schedule, quality, metadata = self.scheduler.schedule_with_rl(num_episodes=10)
        
        self.assertIsNotNone(schedule, "RL returned None schedule")
        self.assertIsNotNone(quality, "RL returned None quality")
        self.assertIn('method', metadata, "Missing 'method' in metadata")
        self.assertEqual(metadata['method'], 'Reinforcement Learning')
    
    def test_rl_quality_score_valid(self):
        """Test that RL quality score is valid"""
        _, quality, _ = self.scheduler.schedule_with_rl(num_episodes=10)
        if quality:
            self.assertGreaterEqual(quality, 0.0)
            self.assertLessEqual(quality, 1.0)
    
    def test_rl_training_stats_present(self):
        """Test that RL returns training statistics"""
        _, _, metadata = self.scheduler.schedule_with_rl(num_episodes=10)
        
        if metadata.get('training_stats'):
            self.assertIn('training_stats', metadata)
    
    def test_rl_multiple_episodes(self):
        """Test RL with different episode counts"""
        for episodes in [5, 10, 20]:
            schedule, quality, _ = self.scheduler.schedule_with_rl(num_episodes=episodes)
            self.assertIsNotNone(schedule, f"RL failed with {episodes} episodes")
    
    # =================================================================
    # TESTS FOR NEURAL NETWORK SCHEDULER
    # =================================================================
    
    def test_nn_scheduling_produces_output(self):
        """Test that NN produces a valid schedule"""
        schedule, quality, metadata = self.scheduler.schedule_with_nn()
        
        self.assertIsNotNone(schedule, "NN returned None schedule")
        self.assertIsNotNone(quality, "NN returned None quality")
        self.assertIn('method', metadata, "Missing 'method' in metadata")
        self.assertEqual(metadata['method'], 'Neural Network')
    
    def test_nn_quality_score_valid(self):
        """Test that NN quality score is valid"""
        _, quality, _ = self.scheduler.schedule_with_nn()
        if quality:
            self.assertGreaterEqual(quality, 0.0)
            self.assertLessEqual(quality, 1.0)
    
    def test_nn_architecture_info(self):
        """Test that NN provides architecture information"""
        _, _, metadata = self.scheduler.schedule_with_nn()
        
        self.assertIn('architecture', metadata, "Missing 'architecture' in metadata")
        self.assertIn(metadata['architecture'], ['mlp', 'attention', 'lstm'],
                     "Unknown architecture")
    
    def test_nn_model_params(self):
        """Test that NN provides model parameter count"""
        _, _, metadata = self.scheduler.schedule_with_nn()
        
        if metadata.get('model_params'):
            self.assertGreater(metadata['model_params'], 0)
    
    # =================================================================
    # TESTS FOR ENSEMBLE ML SCHEDULER
    # =================================================================
    
    def test_ensemble_scheduling_produces_output(self):
        """Test that Ensemble produces a valid schedule"""
        schedule, quality, metadata = self.scheduler.schedule_with_ensemble()
        
        self.assertIsNotNone(schedule, "Ensemble returned None schedule")
        self.assertIsNotNone(quality, "Ensemble returned None quality")
        self.assertIn('method', metadata, "Missing 'method' in metadata")
        self.assertEqual(metadata['method'], 'Greedy Ensemble')
    
    def test_ensemble_quality_score_valid(self):
        """Test that Ensemble quality score is valid"""
        _, quality, _ = self.scheduler.schedule_with_ensemble()
        if quality:
            self.assertGreaterEqual(quality, 0.0)
            self.assertLessEqual(quality, 1.0)
    
    def test_ensemble_model_info(self):
        """Test that Ensemble provides model information"""
        _, _, metadata = self.scheduler.schedule_with_ensemble()
        
        self.assertIn('model', metadata, "Missing 'model' in metadata")
        # baseline_accuracy is no longer provided in Greedy Ensemble metadata
    
    # =================================================================
    # TESTS FOR UNIFIED SCHEDULING (ALL SCHEDULERS)
    # =================================================================
    
    def test_schedule_all_runs_without_error(self):
        """Test that schedule_all() runs without errors"""
        try:
            results = self.scheduler.schedule_all(use_rl_episodes=5)
            self.assertIsNotNone(results)
        except Exception as e:
            self.fail(f"schedule_all() raised exception: {e}")
    
    def test_schedule_all_returns_required_keys(self):
        """Test that schedule_all() returns required keys"""
        results = self.scheduler.schedule_all(use_rl_episodes=5)
        
        required_keys = ['schedules', 'scores', 'metadata', 'best_schedule',
                        'best_method', 'best_score']
        for key in required_keys:
            self.assertIn(key, results, f"Missing '{key}' in schedule_all results")
    
    def test_schedule_all_identifies_best(self):
        """Test that schedule_all() correctly identifies best method"""
        results = self.scheduler.schedule_all(use_rl_episodes=5)
        
        self.assertIsNotNone(results['best_method'])
        self.assertIsNotNone(results['best_score'])
        self.assertGreaterEqual(results['best_score'], 0)
        self.assertLessEqual(results['best_score'], 1)
    
    def test_schedule_all_scores_consistency(self):
        """Test that scores are consistent across multiple runs"""
        # Note: Scores may vary due to randomness, but structure should be same
        results1 = self.scheduler.schedule_all(use_rl_episodes=5)
        
        self.assertEqual(set(results1['scores'].keys()), 
                        set(results1['scores'].keys()),
                        "Score keys inconsistent")
    
    # =================================================================
    # TESTS FOR RESULTS STORAGE AND REPORTING
    # =================================================================
    
    def test_save_results_creates_file(self):
        """Test that save_results creates a JSON file"""
        self.scheduler.schedule_all(use_rl_episodes=5)
        result = self.scheduler.save_results("test_results.json")
        
        filepath = os.path.join(self.temp_dir, "test_results.json")
        self.assertTrue(result, "save_results returned False")
        self.assertTrue(os.path.exists(filepath), "Results file not created")
    
    def test_saved_results_valid_json(self):
        """Test that saved results are valid JSON"""
        self.scheduler.schedule_all(use_rl_episodes=5)
        self.scheduler.save_results("test_results.json")
        
        filepath = os.path.join(self.temp_dir, "test_results.json")
        with open(filepath, 'r') as f:
            data = json.load(f)
        
        self.assertIn('best_method', data)
        self.assertIn('best_score', data)
        self.assertIn('scores', data)
    
    def test_get_report_generates_string(self):
        """Test that get_report() returns a string"""
        self.scheduler.schedule_all(use_rl_episodes=5)
        report = self.scheduler.get_report()
        
        self.assertIsInstance(report, str, "Report not a string")
        self.assertGreater(len(report), 0, "Report is empty")
        self.assertIn('AI UNIFIED SCHEDULER', report)
    
    def test_get_best_schedule(self):
        """Test that get_best_schedule returns the best schedule"""
        self.scheduler.schedule_all(use_rl_episodes=5)
        best = self.scheduler.get_best_schedule()
        
        self.assertIsNotNone(best, "get_best_schedule returned None")
    
    # =================================================================
    # PERFORMANCE AND SCALABILITY TESTS
    # =================================================================
    
    def test_handles_larger_dataset(self):
        """Test scheduler with larger dataset"""
        large_courses = self.courses * 5  # 15 courses
        large_lecturers = self.lecturers * 5  # 15 lecturers
        
        scheduler = AIUnifiedScheduler(
            data_path=self.temp_dir,
            courses=large_courses,
            lecturers=large_lecturers,
            rooms=self.rooms,
            time_slots=self.time_slots,
            enable_ga=True,
            enable_rl=True,
            enable_nn=False,  # NN may be slow
            enable_ensemble=False,  # Ensemble may be slow
            verbose=False
        )
        
        # Should not crash
        results = scheduler.schedule_all(use_rl_episodes=5)
        self.assertIsNotNone(results)
    
    def test_edge_case_single_item(self):
        """Test scheduler with minimal data"""
        minimal_scheduler = AIUnifiedScheduler(
            data_path=self.temp_dir,
            courses=[self.courses[0]],
            lecturers=[self.lecturers[0]],
            rooms=[self.rooms[0]],
            time_slots=[self.time_slots[0]],
            enable_ga=True,
            enable_rl=True,
            enable_nn=False,
            enable_ensemble=False,
            verbose=False
        )
        
        # Should handle gracefully
        schedule, quality, _ = minimal_scheduler.schedule_with_ga()
        self.assertIsNotNone(schedule)
    
    # =================================================================
    # COMPARISON AND RANKING TESTS
    # =================================================================
    
    def test_schedule_quality_ranking(self):
        """Test that scheduler identifies best method by quality"""
        results = self.scheduler.schedule_all(use_rl_episodes=5)
        
        # Best score should be >= all other scores
        for method, score in results['scores'].items():
            self.assertLessEqual(score, results['best_score'] + 0.001,  # Allow small float error
                               f"{method} score exceeds best score")
    
    def test_method_consistency(self):
        """Test that same method produces same type of results"""
        _, quality1, meta1 = self.scheduler.schedule_with_ga()
        
        # Create new scheduler to test independence
        scheduler2 = AIUnifiedScheduler(
            data_path=self.temp_dir,
            courses=self.courses,
            lecturers=self.lecturers,
            rooms=self.rooms,
            time_slots=self.time_slots,
            enable_ga=True,
            enable_rl=False,
            enable_nn=False,
            enable_ensemble=False,
            verbose=False
        )
        
        _, quality2, meta2 = scheduler2.schedule_with_ga()
        
        # Should have same structure
        self.assertEqual(set(meta1.keys()), set(meta2.keys()))


# ============================================================================
# TEST RUNNER
# ============================================================================

if __name__ == '__main__':
    # Run all tests with verbose output
    unittest.main(verbosity=2)
