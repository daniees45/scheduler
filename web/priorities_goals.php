<?php
$page_title = 'Priorities & Goals';
include 'includes/header.php';
requireRole(['student', 'lecturer']);
$user_id = $_SESSION['user_id'];
?>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <h1><i class="fas fa-star"></i> Priorities & Goals</h1>
    <p style="color: var(--text-muted); margin-bottom: 2rem;">
        Define your priorities and goals to help AI optimize your schedule
    </p>
    
    <!-- Priorities Section -->
    <div class="glass-panel" style="padding: 1.5rem; margin-bottom: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h2 style="margin: 0;"><i class="fas fa-list-ul"></i> My Priorities</h2>
            <button class="glass-btn" onclick="openAddPriorityModal()">
                <i class="fas fa-plus"></i> Add Priority
            </button>
        </div>
        <div id="prioritiesList">
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                Loading priorities...
            </div>
        </div>
    </div>
    
    <!-- Goals Section -->
    <div class="glass-panel" style="padding: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h2 style="margin: 0;"><i class="fas fa-bullseye"></i> My Goals</h2>
            <button class="glass-btn" onclick="openAddGoalModal()">
                <i class="fas fa-plus"></i> Add Goal
            </button>
        </div>
        <div id="goalsList">
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                Loading goals...
            </div>
        </div>
    </div>
</div>

<!-- Priority Modal -->
<div id="priorityModal" class="modal" style="display: none;">
    <div class="modal-content glass-panel" style="max-width: 500px; margin: 50px auto; padding: 2rem;">
        <h3 style="margin-top: 0;"><i class="fas fa-star"></i> Add Priority</h3>
        <form id="priorityForm">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Priority Name *</label>
                <input type="text" name="priority_name" class="glass-input" required>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Priority Level *</label>
                <select name="priority_level" class="glass-input" required>
                    <option value="high">High</option>
                    <option value="medium" selected>Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Category *</label>
                <select name="category" class="glass-input" required>
                    <option value="study">Study</option>
                    <option value="work">Work</option>
                    <option value="personal">Personal</option>
                    <option value="health">Health</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Target Hours per Week</label>
                <input type="number" name="target_hours_per_week" class="glass-input" min="0" step="0.5" value="0">
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Description</label>
                <textarea name="description" class="glass-input" rows="3"></textarea>
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="glass-btn" onclick="closeModal('priorityModal')">Cancel</button>
                <button type="submit" class="glass-btn">Save Priority</button>
            </div>
        </form>
    </div>
</div>

<!-- Goal Modal -->
<div id="goalModal" class="modal" style="display: none;">
    <div class="modal-content glass-panel" style="max-width: 500px; margin: 50px auto; padding: 2rem;">
        <h3 style="margin-top: 0;"><i class="fas fa-bullseye"></i> Add Goal</h3>
        <form id="goalForm">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Goal Title *</label>
                <input type="text" name="goal_title" class="glass-input" required>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Category *</label>
                <select name="category" class="glass-input" required>
                    <option value="academic">Academic</option>
                    <option value="career">Career</option>
                    <option value="personal">Personal</option>
                    <option value="health">Health</option>
                    <option value="skill">Skill Development</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Priority Level *</label>
                <select name="priority_level" class="glass-input" required>
                    <option value="high">High</option>
                    <option value="medium" selected>Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Target Completion Date</label>
                <input type="date" name="target_completion_date" class="glass-input">
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem;">Description</label>
                <textarea name="description" class="glass-input" rows="3"></textarea>
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="glass-btn" onclick="closeModal('goalModal')">Cancel</button>
                <button type="submit" class="glass-btn">Save Goal</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 1000;
    overflow-y: auto;
}

.priority-card, .goal-card {
    padding: 1rem;
    background: rgba(15, 23, 42, 0.55);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 0.75rem;
    margin-bottom: 1rem;
}

.priority-high { border-left: 4px solid #ef4444; }
.priority-medium { border-left: 4px solid #f59e0b; }
.priority-low { border-left: 4px solid #6b7280; }

.progress-bar {
    height: 8px;
    background: #374151;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 8px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
}
</style>

<script>
async function loadPriorities() {
    try {
        const response = await fetch('api/personal_priorities.php?action=list_priorities');
        const data = await response.json();
        
        if (data.success && data.priorities.length > 0) {
            let html = '';
            data.priorities.forEach(p => {
                html += `
                    <div class="priority-card priority-${p.priority_level}">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 0.5rem 0;">${p.priority_name}</h3>
                                <div style="font-size: 14px; color: var(--text-muted); margin-bottom: 0.5rem;">
                                    <span class="type-pill" style="margin-right: 8px;">${p.category}</span>
                                    <span class="type-pill" style="background: rgba(${p.priority_level === 'high' ? '239,68,68' : p.priority_level === 'medium' ? '245,158,11' : '107,114,128'},0.3);">
                                        ${p.priority_level}
                                    </span>
                                </div>
                                ${p.description ? `<p style="font-size: 14px; margin: 0.5rem 0;">${p.description}</p>` : ''}
                                ${p.target_hours_per_week > 0 ? `<div style="font-size: 13px; color: var(--text-muted);">Target: ${p.target_hours_per_week}h/week</div>` : ''}
                            </div>
                            <button class="glass-btn small" onclick="deletePriority(${p.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            document.getElementById('prioritiesList').innerHTML = html;
        } else {
            document.getElementById('prioritiesList').innerHTML = 
                '<div style="text-align: center; padding: 40px; color: var(--text-muted);">No priorities yet. Add your first priority!</div>';
        }
    } catch (error) {
        document.getElementById('prioritiesList').innerHTML = '<div style="color: #ef4444;">Failed to load priorities</div>';
    }
}

async function loadGoals() {
    try {
        const response = await fetch('api/personal_priorities.php?action=list_goals');
        const data = await response.json();
        
        if (data.success && data.goals.length > 0) {
            let html = '';
            data.goals.forEach(g => {
                html += `
                    <div class="goal-card priority-${g.priority_level}">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 0.5rem 0;">${g.goal_title}</h3>
                                <div style="font-size: 14px; color: var(--text-muted); margin-bottom: 0.5rem;">
                                    <span class="type-pill" style="margin-right: 8px;">${g.category}</span>
                                    ${g.target_completion_date ? `<span style="font-size: 13px;"><i class="fas fa-calendar"></i> ${g.target_completion_date}</span>` : ''}
                                </div>
                                ${g.description ? `<p style="font-size: 14px; margin: 0.5rem 0;">${g.description}</p>` : ''}
                                <div style="display: flex; align-items: center; gap: 10px; margin-top: 8px;">
                                    <span style="font-size: 13px;">Progress: ${g.progress_percentage}%</span>
                                    <div class="progress-bar" style="flex: 1;">
                                        <div class="progress-fill" style="width: ${g.progress_percentage}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <button class="glass-btn small" onclick="updateProgress(${g.id}, ${g.progress_percentage})" title="Update Progress">
                                    <i class="fas fa-chart-line"></i>
                                </button>
                                <button class="glass-btn small" onclick="deleteGoal(${g.id})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            document.getElementById('goalsList').innerHTML = html;
        } else {
            document.getElementById('goalsList').innerHTML = 
                '<div style="text-align: center; padding: 40px; color: var(--text-muted);">No goals yet. Set your first goal!</div>';
        }
    } catch (error) {
        document.getElementById('goalsList').innerHTML = '<div style="color: #ef4444;">Failed to load goals</div>';
    }
}

function openAddPriorityModal() {
    document.getElementById('priorityModal').style.display = 'block';
}

function openAddGoalModal() {
    document.getElementById('goalModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

document.getElementById('priorityForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);
    
    try {
        const response = await fetch('api/personal_priorities.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'add_priority', ...data})
        });
        const result = await response.json();
        if (result.success) {
            closeModal('priorityModal');
            e.target.reset();
            loadPriorities();
            alert('Priority added successfully!');
        } else {
            alert('Failed to add priority: ' + result.error);
        }
    } catch (error) {
        alert('Error adding priority');
    }
});

document.getElementById('goalForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);
    
    try {
        const response = await fetch('api/personal_priorities.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'add_goal', ...data})
        });
        const result = await response.json();
        if (result.success) {
            closeModal('goalModal');
            e.target.reset();
            loadGoals();
            alert('Goal added successfully!');
        } else {
            alert('Failed to add goal: ' + result.error);
        }
    } catch (error) {
        alert('Error adding goal');
    }
});

async function deletePriority(id) {
    if (!confirm('Delete this priority?')) return;
    try {
        const response = await fetch(`api/personal_priorities.php?action=delete_priority&id=${id}`, {method: 'POST'});
        const data = await response.json();
        if (data.success) {
            loadPriorities();
        }
    } catch (error) {
        alert('Failed to delete priority');
    }
}

async function deleteGoal(id) {
    if (!confirm('Delete this goal?')) return;
    try {
        const response = await fetch(`api/personal_priorities.php?action=delete_goal&id=${id}`, {method: 'POST'});
        const data = await response.json();
        if (data.success) {
            loadGoals();
        }
    } catch (error) {
        alert('Failed to delete goal');
    }
}

async function updateProgress(id, currentProgress) {
    const newProgress = prompt(`Update progress (current: ${currentProgress}%):`, currentProgress);
    if (newProgress === null) return;
    
    try {
        const response = await fetch('api/personal_priorities.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=update_goal_progress&id=${id}&progress=${newProgress}`
        });
        const data = await response.json();
        if (data.success) {
            loadGoals();
        }
    } catch (error) {
        alert('Failed to update progress');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadPriorities();
    loadGoals();
});
</script>

<?php include 'includes/footer.php'; ?>
