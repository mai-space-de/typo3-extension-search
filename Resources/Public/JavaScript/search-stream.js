(() => {
    const ENDPOINT = '/api/search/stream';

    const parseEvents = (buffer, onEvent) => {
        let boundary;
        while ((boundary = buffer.indexOf('\n\n')) !== -1) {
            const rawEvent = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);

            let eventName = 'message';
            let data = '';
            for (const line of rawEvent.split('\n')) {
                if (line.startsWith('event:')) {
                    eventName = line.slice(6).trim();
                } else if (line.startsWith('data:')) {
                    data = line.slice(5).trim();
                }
            }

            if (data !== '') {
                try {
                    onEvent(eventName, JSON.parse(data));
                } catch {
                    // Malformed frame — skip it rather than break the whole stream.
                }
            }
        }

        return buffer;
    };

    const updateHistoryField = (container, history) => {
        const form = container.closest('.search-form') || document.querySelector('.search-form');
        const field = form?.querySelector('[data-search-history-field]');
        if (field) {
            field.value = JSON.stringify(history ?? []);
        }
    };

    const renderSources = (container, sources) => {
        const list = container.querySelector('[data-search-stream-sources]');
        const wrapper = container.querySelector('[data-search-stream-sources-wrapper]');
        if (!list || !wrapper || !Array.isArray(sources) || sources.length === 0) {
            return;
        }

        list.innerHTML = '';
        for (const source of sources) {
            const item = document.createElement('li');
            item.className = 'search-synthesis__sources-item';

            const link = document.createElement('a');
            link.className = 'search-synthesis__sources-link';
            link.href = source.url;
            link.setAttribute('data-turbo-frame', '_top');
            link.textContent = source.title;

            item.appendChild(link);
            list.appendChild(item);
        }

        wrapper.hidden = false;
    };

    const initStreamContainer = async (container) => {
        if (container.dataset.searchStreamInitialized) {
            return;
        }
        container.dataset.searchStreamInitialized = '1';

        const query = container.dataset.searchStreamQuery || '';
        const type = container.dataset.searchStreamType || '';
        const history = container.dataset.searchStreamHistory || '';
        const answerEl = container.querySelector('[data-search-stream-answer]');
        const statusEl = container.querySelector('[data-search-stream-status]');

        if (!query || !answerEl) {
            return;
        }

        try {
            const response = await fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query, type, history }),
            });

            if (!response.ok || !response.body) {
                throw new Error(`Stream request failed with status ${response.status}`);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) {
                    break;
                }

                buffer += decoder.decode(value, { stream: true });
                buffer = parseEvents(buffer, (eventName, payload) => {
                    if (eventName === 'token') {
                        answerEl.textContent += payload.token;
                        if (statusEl) {
                            statusEl.textContent = '';
                        }
                    } else if (eventName === 'done') {
                        renderSources(container, payload.sources);
                        updateHistoryField(container, payload.history);
                        if (statusEl) {
                            statusEl.textContent = '';
                        }
                    } else if (eventName === 'error') {
                        if (statusEl) {
                            statusEl.textContent = payload.message || 'The answer could not be generated.';
                        }
                    }
                });
            }
        } catch {
            if (statusEl) {
                statusEl.textContent = 'The answer could not be generated.';
            }
        }
    };

    const scan = (root) => {
        root.querySelectorAll('[data-search-stream]').forEach((container) => {
            initStreamContainer(container);
        });
    };

    scan(document);

    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) {
                    return;
                }

                if (node.matches('[data-search-stream]')) {
                    initStreamContainer(node);
                } else {
                    scan(node);
                }
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
