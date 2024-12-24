<h1>Projects</h1>
<?php if (!empty($projects_data)): ?>
    <table class="projects-table">
        <thead>
            <tr>
                <th>Project Name</th>
                <th>Description</th>
                <th>Update</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($projects_data as $project): 
                // Ensure keys exist to avoid notices
                $name        = isset($project['name']) ? $project['name'] : 'No Name';
                $description = isset($project['description']) ? $project['description'] : 'No Description';
            ?>
                <tr>
                    <td><?php echo esc_html($name); ?></td>
                    <td><?php echo esc_html($description); ?></td>
                    <td>
                        <!-- Update button -->
                        <button 
                            class="update-project-button" 
                            data-project-name="<?php echo esc_attr($name); ?>">
                            Update
                        </button>
                        <!-- Message container -->
                        <div class="update-message-container" aria-live="polite"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No projects found.</p>
<?php endif; ?>