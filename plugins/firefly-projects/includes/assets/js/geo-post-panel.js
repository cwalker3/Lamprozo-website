/**
 * Firefly Projects - GEO Post Settings Panel
 *
 * Adds LLM optimization fields to blog posts via Gutenberg sidebar panel.
 * Fields: AI Summary, Article Type, Key Facts, FAQ Schema
 */
(function(wp) {
    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editor;
    const { 
        TextareaControl, 
        SelectControl, 
        Button, 
        PanelBody,
        TextControl
    } = wp.components;
    const { useSelect, useDispatch } = wp.data;
    const { useState } = wp.element;
    const { __ } = wp.i18n;
    const el = wp.element.createElement;

    /**
     * Word counter helper
     */
    const countWords = (text) => {
        if (!text) return 0;
        return text.trim().split(/\s+/).filter(word => word.length > 0).length;
    };

    /**
     * Safe JSON parse helper
     */
    const safeJsonParse = (str, defaultVal) => {
        if (!str) return defaultVal;
        try {
            return JSON.parse(str);
        } catch (e) {
            return defaultVal;
        }
    };

    /**
     * Key Facts Repeater Component
     */
    const KeyFactsRepeater = ({ factsJson, onChange }) => {
        const facts = safeJsonParse(factsJson, []);

        const addFact = () => {
            const newFacts = [...facts, { fact: '', source: '' }];
            onChange(JSON.stringify(newFacts));
        };

        const updateFact = (index, field, value) => {
            const newFacts = [...facts];
            newFacts[index] = { ...newFacts[index], [field]: value };
            onChange(JSON.stringify(newFacts));
        };

        const removeFact = (index) => {
            const newFacts = facts.filter((_, i) => i !== index);
            onChange(JSON.stringify(newFacts));
        };

        return el('div', { className: 'geo-repeater' },
            facts.map((fact, index) => 
                el('div', { key: index, className: 'geo-repeater-item' },
                    el(TextControl, {
                        label: __('Fact/Statistic', 'firefly-projects'),
                        value: fact.fact || '',
                        onChange: (val) => updateFact(index, 'fact', val),
                        placeholder: __('41% citation boost with statistics', 'firefly-projects')
                    }),
                    el(TextControl, {
                        label: __('Source', 'firefly-projects'),
                        value: fact.source || '',
                        onChange: (val) => updateFact(index, 'source', val),
                        placeholder: __('Study Name 2024', 'firefly-projects')
                    }),
                    el(Button, {
                        isDestructive: true,
                        isSmall: true,
                        onClick: () => removeFact(index),
                        className: 'geo-remove-btn'
                    }, __('Remove', 'firefly-projects'))
                )
            ),
            el(Button, {
                isSecondary: true,
                isSmall: true,
                onClick: addFact,
                className: 'geo-add-btn'
            }, __('+ Add Key Fact', 'firefly-projects'))
        );
    };

    /**
     * FAQ Repeater Component (schema only, not visible on frontend)
     */
    const FAQRepeater = ({ faqJson, onChange }) => {
        const faqs = safeJsonParse(faqJson, []);

        const addFaq = () => {
            const newFaqs = [...faqs, { question: '', answer: '' }];
            onChange(JSON.stringify(newFaqs));
        };

        const updateFaq = (index, field, value) => {
            const newFaqs = [...faqs];
            newFaqs[index] = { ...newFaqs[index], [field]: value };
            onChange(JSON.stringify(newFaqs));
        };

        const removeFaq = (index) => {
            const newFaqs = faqs.filter((_, i) => i !== index);
            onChange(JSON.stringify(newFaqs));
        };

        return el('div', { className: 'geo-repeater' },
            faqs.map((faq, index) => 
                el('div', { key: index, className: 'geo-repeater-item geo-faq-item' },
                    el(TextControl, {
                        label: __('Question', 'firefly-projects'),
                        value: faq.question || '',
                        onChange: (val) => updateFaq(index, 'question', val),
                        placeholder: __('What is your question?', 'firefly-projects')
                    }),
                    el(TextareaControl, {
                        label: __('Answer', 'firefly-projects'),
                        value: faq.answer || '',
                        onChange: (val) => updateFaq(index, 'answer', val),
                        placeholder: __('Provide a concise answer...', 'firefly-projects'),
                        rows: 3
                    }),
                    el(Button, {
                        isDestructive: true,
                        isSmall: true,
                        onClick: () => removeFaq(index),
                        className: 'geo-remove-btn'
                    }, __('Remove', 'firefly-projects'))
                )
            ),
            el(Button, {
                isSecondary: true,
                isSmall: true,
                onClick: addFaq,
                className: 'geo-add-btn'
            }, __('+ Add FAQ', 'firefly-projects')),
            el('p', { className: 'geo-tip' },
                __('For visible FAQs with accordion UI, use the FAQ Block in the content area.', 'firefly-projects')
            )
        );
    };

    /**
     * Main GEO Post Panel Component
     */
    const GeoPostPanel = () => {
        // Get current post data
        const { postType, meta } = useSelect((select) => {
            const editor = select('core/editor');
            return {
                postType: editor.getCurrentPostType(),
                meta: editor.getEditedPostAttribute('meta') || {}
            };
        }, []);

        const { editPost } = useDispatch('core/editor');

        // Only show for posts
        if (postType !== 'post') {
            return null;
        }

        const updateMeta = (key, value) => {
            editPost({ meta: { ...meta, [key]: value } });
        };

        const summary = meta._geo_summary || '';
        const wordCount = countWords(summary);
        const isOverLimit = wordCount > 120;

        const keyFactsCount = safeJsonParse(meta._geo_key_facts, []).length;
        const faqCount = safeJsonParse(meta._geo_faq, []).length;

        return el(PluginDocumentSettingPanel, {
            name: 'geo-post-settings',
            title: __('GEO Settings', 'firefly-projects'),
            icon: 'visibility',
            className: 'firefly-geo-panel'
        },
            // AI Summary Section
            el('div', { className: 'geo-section' },
                el(TextareaControl, {
                    label: __('AI Summary (Answer Capsule)', 'firefly-projects'),
                    help: __('120 words max. This summary may appear in AI search results.', 'firefly-projects'),
                    value: summary,
                    onChange: (val) => updateMeta('_geo_summary', val),
                    rows: 4,
                    className: isOverLimit ? 'geo-over-limit' : ''
                }),
                el('div', { 
                    className: 'geo-word-count' + (isOverLimit ? ' over-limit' : '')
                }, 
                    wordCount + '/120 ' + __('words', 'firefly-projects')
                )
            ),

            // Article Type Section
            el('div', { className: 'geo-section' },
                el(SelectControl, {
                    label: __('Article Type', 'firefly-projects'),
                    help: __('Schema.org type for structured data', 'firefly-projects'),
                    value: meta._geo_article_type || 'BlogPosting',
                    options: [
                        { label: __('Blog Post', 'firefly-projects'), value: 'BlogPosting' },
                        { label: __('How-To Guide', 'firefly-projects'), value: 'HowTo' },
                        { label: __('News Article', 'firefly-projects'), value: 'NewsArticle' }
                    ],
                    onChange: (val) => updateMeta('_geo_article_type', val)
                })
            ),

            // Key Facts Section
            el(PanelBody, {
                title: __('Key Facts', 'firefly-projects') + (keyFactsCount > 0 ? ' (' + keyFactsCount + ')' : ''),
                initialOpen: false,
                className: 'geo-panel-body'
            },
                el('p', { className: 'geo-panel-description' },
                    __('Quotable statistics boost citations by 41%. Add facts with sources.', 'firefly-projects')
                ),
                el(KeyFactsRepeater, {
                    factsJson: meta._geo_key_facts || '',
                    onChange: (val) => updateMeta('_geo_key_facts', val)
                })
            ),

            // FAQ Section
            el(PanelBody, {
                title: __('FAQ Schema', 'firefly-projects') + (faqCount > 0 ? ' (' + faqCount + ')' : ''),
                initialOpen: false,
                className: 'geo-panel-body'
            },
                el('p', { className: 'geo-panel-description' },
                    __('FAQPage schema provides 31% visibility boost. These generate schema only.', 'firefly-projects')
                ),
                el(FAQRepeater, {
                    faqJson: meta._geo_faq || '',
                    onChange: (val) => updateMeta('_geo_faq', val)
                })
            )
        );
    };

    // Register the plugin
    registerPlugin('firefly-geo-post-settings', {
        render: GeoPostPanel,
        icon: 'visibility'
    });

})(window.wp);
