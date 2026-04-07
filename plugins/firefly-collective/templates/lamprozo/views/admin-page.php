<div class="lamprozo-admin-wrap">
    <header class="lamprozo-header">
        <h1>Lamprozo Template</h1>
        <p class="lamprozo-version">Version 1.0.0</p>
    </header>

    <div class="lamprozo-content">
        <section class="lamprozo-section">
            <h2>What is the Firefly Template System?</h2>
            <p>The Firefly Template System allows you to create completely isolated website experiences within a single WordPress installation. Each template has its own:</p>
            <ul>
                <li><strong>Pages & Content</strong> - Scoped using <code>_firefly_template</code> meta</li>
                <li><strong>Navigation Menus</strong> - Separate menus for each template</li>
                <li><strong>Visual Design</strong> - Custom CSS, JS, and layout</li>
                <li><strong>Admin Interface</strong> - Template-specific settings and tools</li>
            </ul>
        </section>

        <section class="lamprozo-section">
            <h2>How It Works</h2>
            <div class="lamprozo-grid">
                <div class="lamprozo-card">
                    <h3>1. Schema Definition</h3>
                    <p>Define your template's structure in <code>data/schemas/lamprozo-schema.json</code></p>
                </div>
                <div class="lamprozo-card">
                    <h3>2. Content Creation</h3>
                    <p>Pages, posts, and menus are created from the schema with proper scoping</p>
                </div>
                <div class="lamprozo-card">
                    <h3>3. Auto-Activation</h3>
                    <p>Switch templates via Customizer - content auto-creates if missing</p>
                </div>
            </div>
        </section>

        <section class="lamprozo-section">
            <h2>API Test</h2>
            <button id="lamprozo-test-api" class="lamprozo-button">Test REST API</button>
            <pre id="lamprozo-api-response" class="lamprozo-response"></pre>
        </section>

        <section class="lamprozo-section lamprozo-features">
            <h2>Template Capabilities</h2>
            <p>Customize this section to showcase what your template can do:</p>
            <ul>
                <li>Custom page layouts and views</li>
                <li>Template-specific REST endpoints</li>
                <li>Isolated admin interfaces</li>
                <li>Dynamic asset loading</li>
            </ul>
        </section>
    </div>
</div>
