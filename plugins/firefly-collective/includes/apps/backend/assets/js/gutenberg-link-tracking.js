/**
 * Gutenberg Link Click Tracking Integration
 *
 * Adds a sidebar panel showing all links in the post with toggle controls.
 */

(function() {
    // Simple MD5 implementation (to match PHP md5() for hash generation)
    function md5(string) {
        function rotateLeft(value, shift) {
            return (value << shift) | (value >>> (32 - shift));
        }
        function addUnsigned(x, y) {
            const lsw = (x & 0xFFFF) + (y & 0xFFFF);
            const msw = (x >> 16) + (y >> 16) + (lsw >> 16);
            return (msw << 16) | (lsw & 0xFFFF);
        }
        function md5cmn(q, a, b, x, s, t) {
            return addUnsigned(rotateLeft(addUnsigned(addUnsigned(a, q), addUnsigned(x, t)), s), b);
        }
        function md5ff(a, b, c, d, x, s, t) {
            return md5cmn((b & c) | ((~b) & d), a, b, x, s, t);
        }
        function md5gg(a, b, c, d, x, s, t) {
            return md5cmn((b & d) | (c & (~d)), a, b, x, s, t);
        }
        function md5hh(a, b, c, d, x, s, t) {
            return md5cmn(b ^ c ^ d, a, b, x, s, t);
        }
        function md5ii(a, b, c, d, x, s, t) {
            return md5cmn(c ^ (b | (~d)), a, b, x, s, t);
        }
        function convertToWordArray(string) {
            let wordArray = [];
            for (let i = 0; i < string.length * 8; i += 8) {
                wordArray[i >> 5] |= (string.charCodeAt(i / 8) & 0xFF) << (i % 32);
            }
            return wordArray;
        }
        function wordToHex(value) {
            let hex = '', byte;
            for (let i = 0; i < 4; i++) {
                byte = (value >>> (i * 8)) & 0xFF;
                hex += ('0' + byte.toString(16)).slice(-2);
            }
            return hex;
        }

        const x = convertToWordArray(string);
        let a = 0x67452301, b = 0xEFCDAB89, c = 0x98BADCFE, d = 0x10325476;

        x[string.length * 8 >> 5] |= 0x80 << (string.length * 8 % 32);
        x[(((string.length * 8 + 64) >>> 9) << 4) + 14] = string.length * 8;

        for (let i = 0; i < x.length; i += 16) {
            const olda = a, oldb = b, oldc = c, oldd = d;
            a = md5ff(a, b, c, d, x[i], 7, 0xD76AA478);
            d = md5ff(d, a, b, c, x[i + 1], 12, 0xE8C7B756);
            c = md5ff(c, d, a, b, x[i + 2], 17, 0x242070DB);
            b = md5ff(b, c, d, a, x[i + 3], 22, 0xC1BDCEEE);
            a = md5ff(a, b, c, d, x[i + 4], 7, 0xF57C0FAF);
            d = md5ff(d, a, b, c, x[i + 5], 12, 0x4787C62A);
            c = md5ff(c, d, a, b, x[i + 6], 17, 0xA8304613);
            b = md5ff(b, c, d, a, x[i + 7], 22, 0xFD469501);
            a = md5ff(a, b, c, d, x[i + 8], 7, 0x698098D8);
            d = md5ff(d, a, b, c, x[i + 9], 12, 0x8B44F7AF);
            c = md5ff(c, d, a, b, x[i + 10], 17, 0xFFFF5BB1);
            b = md5ff(b, c, d, a, x[i + 11], 22, 0x895CD7BE);
            a = md5ff(a, b, c, d, x[i + 12], 7, 0x6B901122);
            d = md5ff(d, a, b, c, x[i + 13], 12, 0xFD987193);
            c = md5ff(c, d, a, b, x[i + 14], 17, 0xA679438E);
            b = md5ff(b, c, d, a, x[i + 15], 22, 0x49B40821);
            a = md5gg(a, b, c, d, x[i + 1], 5, 0xF61E2562);
            d = md5gg(d, a, b, c, x[i + 6], 9, 0xC040B340);
            c = md5gg(c, d, a, b, x[i + 11], 14, 0x265E5A51);
            b = md5gg(b, c, d, a, x[i], 20, 0xE9B6C7AA);
            a = md5gg(a, b, c, d, x[i + 5], 5, 0xD62F105D);
            d = md5gg(d, a, b, c, x[i + 10], 9, 0x02441453);
            c = md5gg(c, d, a, b, x[i + 15], 14, 0xD8A1E681);
            b = md5gg(b, c, d, a, x[i + 4], 20, 0xE7D3FBC8);
            a = md5gg(a, b, c, d, x[i + 9], 5, 0x21E1CDE6);
            d = md5gg(d, a, b, c, x[i + 14], 9, 0xC33707D6);
            c = md5gg(c, d, a, b, x[i + 3], 14, 0xF4D50D87);
            b = md5gg(b, c, d, a, x[i + 8], 20, 0x455A14ED);
            a = md5gg(a, b, c, d, x[i + 13], 5, 0xA9E3E905);
            d = md5gg(d, a, b, c, x[i + 2], 9, 0xFCEFA3F8);
            c = md5gg(c, d, a, b, x[i + 7], 14, 0x676F02D9);
            b = md5gg(b, c, d, a, x[i + 12], 20, 0x8D2A4C8A);
            a = md5hh(a, b, c, d, x[i + 5], 4, 0xFFFA3942);
            d = md5hh(d, a, b, c, x[i + 8], 11, 0x8771F681);
            c = md5hh(c, d, a, b, x[i + 11], 16, 0x6D9D6122);
            b = md5hh(b, c, d, a, x[i + 14], 23, 0xFDE5380C);
            a = md5hh(a, b, c, d, x[i + 1], 4, 0xA4BEEA44);
            d = md5hh(d, a, b, c, x[i + 4], 11, 0x4BDECFA9);
            c = md5hh(c, d, a, b, x[i + 7], 16, 0xF6BB4B60);
            b = md5hh(b, c, d, a, x[i + 10], 23, 0xBEBFBC70);
            a = md5hh(a, b, c, d, x[i + 13], 4, 0x289B7EC6);
            d = md5hh(d, a, b, c, x[i], 11, 0xEAA127FA);
            c = md5hh(c, d, a, b, x[i + 3], 16, 0xD4EF3085);
            b = md5hh(b, c, d, a, x[i + 6], 23, 0x04881D05);
            a = md5hh(a, b, c, d, x[i + 9], 4, 0xD9D4D039);
            d = md5hh(d, a, b, c, x[i + 12], 11, 0xE6DB99E5);
            c = md5hh(c, d, a, b, x[i + 15], 16, 0x1FA27CF8);
            b = md5hh(b, c, d, a, x[i + 2], 23, 0xC4AC5665);
            a = md5ii(a, b, c, d, x[i], 6, 0xF4292244);
            d = md5ii(d, a, b, c, x[i + 7], 10, 0x432AFF97);
            c = md5ii(c, d, a, b, x[i + 14], 15, 0xAB9423A7);
            b = md5ii(b, c, d, a, x[i + 5], 21, 0xFC93A039);
            a = md5ii(a, b, c, d, x[i + 12], 6, 0x655B59C3);
            d = md5ii(d, a, b, c, x[i + 3], 10, 0x8F0CCC92);
            c = md5ii(c, d, a, b, x[i + 10], 15, 0xFFEFF47D);
            b = md5ii(b, c, d, a, x[i + 1], 21, 0x85845DD1);
            a = md5ii(a, b, c, d, x[i + 8], 6, 0x6FA87E4F);
            d = md5ii(d, a, b, c, x[i + 15], 10, 0xFE2CE6E0);
            c = md5ii(c, d, a, b, x[i + 6], 15, 0xA3014314);
            b = md5ii(b, c, d, a, x[i + 13], 21, 0x4E0811A1);
            a = md5ii(a, b, c, d, x[i + 4], 6, 0xF7537E82);
            d = md5ii(d, a, b, c, x[i + 11], 10, 0xBD3AF235);
            c = md5ii(c, d, a, b, x[i + 2], 15, 0x2AD7D2BB);
            b = md5ii(b, c, d, a, x[i + 9], 21, 0xEB86D391);
            a = addUnsigned(a, olda);
            b = addUnsigned(b, oldb);
            c = addUnsigned(c, oldc);
            d = addUnsigned(d, oldd);
        }
        return wordToHex(a) + wordToHex(b) + wordToHex(c) + wordToHex(d);
    }

    const { createElement, Component } = wp.element;
    const { PluginDocumentSettingPanel } = wp.editor; // Use wp.editor instead of deprecated wp.editPost
    const { ToggleControl, Notice, Spinner } = wp.components;
    const { compose } = wp.compose;
    const { withSelect } = wp.data;
    const { registerPlugin } = wp.plugins;

    /**
     * Link Tracking Panel Component
     */
    const LinkTrackingPanel = compose(
        withSelect((select) => {
            const { getCurrentPostId } = select('core/editor');
            const { getEditedPostContent } = select('core/editor');

            return {
                postId: getCurrentPostId(),
                content: getEditedPostContent()
            };
        })
    )(class extends Component {
        constructor(props) {
            super(props);
            this.state = {
                links: [],
                trackedLinks: {},
                loading: true
            };
        }

        componentDidMount() {
            this.extractLinks();
            this.loadTrackedLinks();
        }

        componentDidUpdate(prevProps) {
            // Re-extract links when content changes
            if (prevProps.content !== this.props.content) {
                this.extractLinks();
            }
        }

        extractLinks() {
            const { content } = this.props;

            // Parse HTML to find links
            const parser = new DOMParser();
            const doc = parser.parseFromString(content, 'text/html');
            const linkElements = doc.querySelectorAll('a[href]');

            const links = Array.from(linkElements).map(link => ({
                url: link.getAttribute('href'),
                text: link.textContent || link.getAttribute('href'),
                hash: this.generateLinkHash(link.getAttribute('href'))
            }));

            // Remove duplicates
            const uniqueLinks = links.filter((link, index, self) =>
                index === self.findIndex((l) => l.url === link.url)
            );

            this.setState({ links: uniqueLinks });
        }

        async loadTrackedLinks() {
            const { postId } = this.props;

            if (!postId) {
                this.setState({ loading: false });
                return;
            }

            try {
                const response = await wp.apiFetch({
                    path: `/firefly-collective/v1/post-tracked-links/${postId}`
                });

                this.setState({
                    trackedLinks: response || {},
                    loading: false
                });
            } catch (error) {
                console.error('Failed to load tracked links:', error);
                this.setState({ loading: false });
            }
        }

        generateLinkHash(url) {
            const { postId } = this.props;
            return md5(url + postId);
        }

        async toggleTracking(link, newValue) {
            const { postId } = this.props;

            try {
                const response = await wp.apiFetch({
                    path: '/firefly-collective/v1/track-link',
                    method: 'POST',
                    data: {
                        post_id: postId,
                        link_url: link.url,
                        link_text: link.text,
                        is_active: newValue
                    }
                });

                if (response.ok) {
                    // Update local state
                    this.setState(prevState => ({
                        trackedLinks: {
                            ...prevState.trackedLinks,
                            [response.link_hash]: {
                                id: response.link_id,
                                url: link.url,
                                is_active: newValue
                            }
                        }
                    }));

                    wp.data.dispatch('core/notices').createNotice(
                        'success',
                        newValue ? 'Click tracking enabled' : 'Click tracking disabled',
                        { type: 'snackbar', isDismissible: true }
                    );
                }
            } catch (error) {
                console.error('Failed to toggle link tracking:', error);
                wp.data.dispatch('core/notices').createNotice(
                    'error',
                    'Failed to update link tracking',
                    { type: 'snackbar', isDismissible: true }
                );
            }
        }

        render() {
            const { links, trackedLinks, loading } = this.state;

            if (loading) {
                return createElement(Spinner);
            }

            if (links.length === 0) {
                return createElement(
                    Notice,
                    { status: 'info', isDismissible: false },
                    'No links found in this post. Add some links to your content to enable click tracking.'
                );
            }

            return createElement(
                'div',
                { className: 'firefly-link-tracking-panel' },
                createElement('p', { style: { marginBottom: '12px', fontSize: '13px', color: '#757575' } },
                    `${links.length} link(s) found in this post:`
                ),
                links.map((link, index) =>
                    createElement(
                        'div',
                        {
                            key: index,
                            style: {
                                marginBottom: '16px',
                                padding: '12px',
                                border: '1px solid #ddd',
                                borderRadius: '4px',
                                backgroundColor: trackedLinks[link.hash]?.is_active ? '#f0f9ff' : '#fff'
                            }
                        },
                        createElement(
                            'div',
                            { style: { marginBottom: '8px' } },
                            createElement(
                                'strong',
                                {
                                    style: {
                                        fontSize: '13px',
                                        display: 'block',
                                        marginBottom: '4px',
                                        overflow: 'hidden',
                                        textOverflow: 'ellipsis',
                                        whiteSpace: 'nowrap'
                                    }
                                },
                                link.text
                            ),
                            createElement(
                                'a',
                                {
                                    href: link.url,
                                    target: '_blank',
                                    rel: 'noopener noreferrer',
                                    style: {
                                        fontSize: '12px',
                                        color: '#666',
                                        textDecoration: 'none',
                                        display: 'block',
                                        overflow: 'hidden',
                                        textOverflow: 'ellipsis',
                                        whiteSpace: 'nowrap'
                                    }
                                },
                                link.url
                            )
                        ),
                        createElement(ToggleControl, {
                            label: 'Track Clicks',
                            checked: trackedLinks[link.hash]?.is_active || false,
                            onChange: (newValue) => this.toggleTracking(link, newValue),
                            help: trackedLinks[link.hash]?.is_active ?
                                'Click tracking is enabled' :
                                'Click tracking is disabled'
                        })
                    )
                )
            );
        }
    });

    // Register the sidebar panel
    registerPlugin('firefly-link-tracking', {
        render: () => createElement(
            PluginDocumentSettingPanel,
            {
                name: 'firefly-link-tracking-panel',
                title: 'Link Click Tracking',
                icon: 'chart-bar'
            },
            createElement(LinkTrackingPanel)
        )
    });

})();
