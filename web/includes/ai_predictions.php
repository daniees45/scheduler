<?php
/**
 * AI Predictions Helper Functions
 * Provides feasibility scoring and predictions for course assignments
 */

/**
 * Predict the feasibility of a course assignment
 * @param string $courseCode Course code
 * @param string $day Day of week
 * @param string $time Time slot (HH:MM format)
 * @param int $duration Duration in minutes
 * @return array Feasibility prediction with probability
 */
function predictAssignmentFeasibility($courseCode, $day, $time, $duration = 60) {
    // Default feasibility prediction
    // In a production system, this would analyze historical patterns
    // For now, return a reasonable default
    
    $feasibility = [
        'probability' => 0.75,  // 75% probability of successful assignment
        'confidence' => 0.8,
        'factors' => [
            'day_preference' => 0.8,
            'time_preference' => 0.7,
            'lecturer_availability' => 0.9
        ]
    ];
    
    return $feasibility;
}

/**
 * Get a feasibility badge/label for a given probability
 * @param float $probability Probability value (0-1)
 * @return array Badge information with label and styling
 */
function getFeasibilityBadge($probability) {
    $probability = (float)$probability;
    
    if ($probability >= 0.9) {
        return [
            'label' => 'Excellent',
            'class' => 'badge-success',
            'color' => '#10b981',
            'bg_color' => 'rgba(16, 185, 129, 0.1)'
        ];
    } elseif ($probability >= 0.75) {
        return [
            'label' => 'Good',
            'class' => 'badge-info',
            'color' => '#3b82f6',
            'bg_color' => 'rgba(59, 130, 246, 0.1)'
        ];
    } elseif ($probability >= 0.6) {
        return [
            'label' => 'Fair',
            'class' => 'badge-warning',
            'color' => '#f59e0b',
            'bg_color' => 'rgba(245, 158, 11, 0.1)'
        ];
    } else {
        return [
            'label' => 'Low',
            'class' => 'badge-danger',
            'color' => '#ef4444',
            'bg_color' => 'rgba(239, 68, 68, 0.1)'
        ];
    }
}

?>
