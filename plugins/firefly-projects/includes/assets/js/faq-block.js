/**
 * Firefly Projects - FAQ Block
 *
 * Custom Gutenberg block for visible FAQ accordion with Schema.org microdata.
 * Use this block for FAQs that should appear in the content area.
 */
(function(wp) {
    const { registerBlockType } = wp.blocks;
    const { useBlockProps } = wp.blockEditor;
    const { Button, TextControl, TextareaControl } = wp.components;
    const { __ } = wp.i18n;
    const el = wp.element.createElement;

    /**
     * FAQ Block Edit Component
     */
    const FaqEdit = ({ attributes, setAttributes }) => {
        const { faqs = [] } = attributes;
        const blockProps = useBlockProps({ className: 'firefly-faq-block-editor' });

        const addFaq = () => {
            setAttributes({
                faqs: [...faqs, { question: '', answer: '' }]
            });
        };

        const updateFaq = (index, field, value) => {
            const newFaqs = [...faqs];
            newFaqs[index] = { ...newFaqs[index], [field]: value };
            setAttributes({ faqs: newFaqs });
        };

        const removeFaq = (index) => {
            const newFaqs = faqs.filter((_, i) => i !== index);
            setAttributes({ faqs: newFaqs });
        };

        const moveFaq = (index, direction) => {
            const newIndex = index + direction;
            if (newIndex < 0 || newIndex >= faqs.length) return;

            const newFaqs = [...faqs];
            const temp = newFaqs[index];
            newFaqs[index] = newFaqs[newIndex];
            newFaqs[newIndex] = temp;
            setAttributes({ faqs: newFaqs });
        };

        return el('div', blockProps,
            el('div', { className: 'faq-block-header' },
                el('h3', null, __('FAQ Section', 'firefly-projects')),
                el('span', { className: 'faq-count' },
                    faqs.length + ' ' + (faqs.length === 1 ? __('question', 'firefly-projects') : __('questions', 'firefly-projects'))
                )
            ),

            faqs.length === 0 && el('p', { className: 'faq-empty-state' },
                __('Add FAQs to display an accordion with Schema.org markup for AI search visibility.', 'firefly-projects')
            ),

            faqs.map((faq, index) =>
                el('div', { key: index, className: 'faq-editor-item' },
                    el('div', { className: 'faq-item-header' },
                        el('span', { className: 'faq-item-number' }, '#' + (index + 1)),
                        el('div', { className: 'faq-item-actions' },
                            el(Button, {
                                isSmall: true,
                                icon: 'arrow-up-alt2',
                                label: __('Move up', 'firefly-projects'),
                                onClick: () => moveFaq(index, -1),
                                disabled: index === 0
                            }),
                            el(Button, {
                                isSmall: true,
                                icon: 'arrow-down-alt2',
                                label: __('Move down', 'firefly-projects'),
                                onClick: () => moveFaq(index, 1),
                                disabled: index === faqs.length - 1
                            }),
                            el(Button, {
                                isSmall: true,
                                isDestructive: true,
                                icon: 'trash',
                                label: __('Remove', 'firefly-projects'),
                                onClick: () => removeFaq(index)
                            })
                        )
                    ),
                    // __next40pxDefaultSize: true opts into the modern
                    // 40px control height (default in WP 7.1+). Without
                    // it, WP 6.8+ logs a deprecation warning on every
                    // editor mount that renders this block.
                    el(TextControl, {
                        __next40pxDefaultSize: true,
                        label: __('Question', 'firefly-projects'),
                        value: faq.question || '',
                        onChange: (val) => updateFaq(index, 'question', val),
                        placeholder: __('Enter your question...', 'firefly-projects')
                    }),
                    el(TextareaControl, {
                        __next40pxDefaultSize: true,
                        label: __('Answer', 'firefly-projects'),
                        value: faq.answer || '',
                        onChange: (val) => updateFaq(index, 'answer', val),
                        placeholder: __('Enter the answer...', 'firefly-projects'),
                        rows: 3
                    })
                )
            ),

            el('div', { className: 'faq-add-wrapper' },
                el(Button, {
                    isPrimary: true,
                    onClick: addFaq,
                    className: 'faq-add-btn'
                }, __('+ Add Question', 'firefly-projects'))
            )
        );
    };

    /**
     * FAQ Block Save Component
     * Returns null because we use PHP render_callback
     */
    const FaqSave = () => {
        return null;
    };

    // Register the block. apiVersion: 3 opts into the iframed editor +
    // modern block-instance API (default expectation in WP 6.3+).
    // Without it the block is treated as API v1 (deprecated since 6.9)
    // and Gutenberg logs a console warning on every editor load.
    registerBlockType('firefly/faq', {
        apiVersion: 3,
        title: __('FAQ Section', 'firefly-projects'),
        description: __('Add frequently asked questions with accordion UI and FAQPage schema for AI visibility.', 'firefly-projects'),
        icon: 'editor-help',
        category: 'common',
        keywords: [
            __('faq', 'firefly-projects'),
            __('questions', 'firefly-projects'),
            __('schema', 'firefly-projects'),
            __('seo', 'firefly-projects'),
            __('geo', 'firefly-projects')
        ],
        supports: {
            html: false,
            align: ['wide', 'full']
        },
        attributes: {
            faqs: {
                type: 'array',
                default: []
            }
        },
        edit: FaqEdit,
        save: FaqSave
    });

})(window.wp);
