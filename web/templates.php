<?php
// web/templates.php
require_once 'includes/header.php';
requireRole(['super_admin', 'faculty_admin']);
?>

<div class="main-content">
    <div class="header-bar">
        <div class="page-title">
            <h1>Schedule Templates</h1>
            <p class="text-muted">Manage your saved scheduling configurations and AI weights.</p>
        </div>
    </div>

    <div class="glass-panel animate-fade-in" style="padding: 2rem;">
        <div id="templatesList" class="grid-layout"
            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
            <!-- Templates will be loaded here -->
            <div class="loading">Loading templates...</div>
        </div>
    </div>
</div>

<script>
    async function loadTemplates() {
        try {
            const response = await fetch('api/manage_templates.php');
            const result = await response.json();
            const container = document.getElementById('templatesList');

            if (result.status === 'success') {
                if (result.data.length === 0) {
                    container.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 2rem;">No templates found. Save a configuration from the Generator to see it here.</div>';
                    return;
                }

                container.innerHTML = result.data.map(template => `
                <div class="glass-panel stat-card animate-fade-in" style="background: rgba(255,255,255,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                        <h3 style="font-size: 1.1rem;">${template.template_name}</h3>
                        <button onclick="deleteTemplate(${template.id})" class="text-btn danger" title="Delete Template">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                        <p><i class="fa-solid fa-calendar"></i> Saved on: ${new Date(template.created_at).toLocaleDateString()}</p>
                    </div>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <span class="status-badge checking" style="font-size: 0.7rem;">Weights: ${Object.keys(template.config_json.weights || {}).length}</span>
                        <span class="status-badge checking" style="font-size: 0.7rem;">Filters: ${Object.keys(template.config_json.filters || {}).length}</span>
                    </div>
                    <div style="margin-top: 1.5rem;">
                        <a href="generate.php?template_id=${template.id}" class="glass-btn small" style="display: inline-block; text-decoration: none; width: 100%; text-align: center;">
                            Apply Template
                        </a>
                    </div>
                </div>
            `).join('');
            }
        } catch (e) {
            console.error('Failed to load templates:', e);
        }
    }

    async function deleteTemplate(id) {
        if (!await showConfirm('Are you sure you want to delete this template?', 'Delete Template')) return;

        try {
            const response = await fetch(`api/manage_templates.php?id=${id}`, { method: 'DELETE' });
            const result = await response.json();
            if (result.status === 'success') {
                loadTemplates();
            }
        } catch (e) {
            console.error('Delete failed:', e);
        }
    }

    document.addEventListener('DOMContentLoaded', loadTemplates);
</script>

<?php include_once 'includes/footer.php'; ?>