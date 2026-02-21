import unittest
import pandas as pd

from validators import validate_required_columns, validate_lecturer_availability
from data_model import Lecturer


class TestValidators(unittest.TestCase):
    def test_validate_required_columns_missing(self):
        df = pd.DataFrame({"course_code": ["CS101"]})
        issues = validate_required_columns(df, ["course_code", "lecturer_name"], "input course CSV")
        self.assertTrue(any("lecturer_name" in issue for issue in issues))

    def test_validate_required_columns_ok(self):
        df = pd.DataFrame({"course_code": ["CS101"], "lecturer_name": ["Dr. A"]})
        issues = validate_required_columns(df, ["course_code", "lecturer_name"], "input course CSV")
        self.assertEqual(issues, [])

    def test_validate_lecturer_availability(self):
        lecturers = {
            "L1": Lecturer(id="L1", name="Dr. A", available_time_slots=[(0, 0)]),
            "L2": Lecturer(id="L2", name="Dr. B", available_time_slots=[(0, 0), (0, 1), (1, 0)])
        }
        issues = validate_lecturer_availability(lecturers, min_slots=2)
        self.assertTrue(any("Dr. A" in issue for issue in issues))
        self.assertFalse(any("Dr. B" in issue for issue in issues))


if __name__ == "__main__":
    unittest.main()
