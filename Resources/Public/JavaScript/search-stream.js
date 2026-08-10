(() => {
    const STREAM_ENDPOINT = '/api/search/stream';
    const ANSWER_ENDPOINT = '/api/search/answer';
    const TOKEN_TIMEOUT_MS = 45000;

    const parseEvents = (buffer, onEvent) => {
        let boundary;
        while ((boundary = buffer.indexOf('\n\n')) !== -1) {
            const rawEvent = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);

            let eventName = 'message';
            let data = '';
            for (const line of rawEvent.split('\n')) {
                if (line.startsWith(':')) {
                    continue;
                }
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
                    // Malformed frame — skip rather than break the stream.
                }
            }
        }

        return buffer;
    };

    const parseContext = (raw) => {
        if (!raw) {
            return [];
        }
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    };

    const labelsFor = (container) => ({
        searching: container.dataset.i18nSearching || 'Searching…',
        loading: container.dataset.i18nLoading || 'Generating answer…',
        error: container.dataset.i18nError || 'The answer could not be generated.',
    });

    const statusMessage = (labels, payload) => {
        if (payload?.phase === 'searching') {
            return labels.searching;
        }
        if (payload?.phase === 'generating') {
            return labels.loading;
        }
        return payload?.message || labels.loading;
    };

    const updateHistoryField = (container, history) => {
        const form = container.closest('.search-form, .mai-search__form')
            || document.querySelector('.search-form, .mai-search__form');
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
            const link = document.createElement('a');
            link.href = source.url;
            link.setAttribute('data-turbo-frame', '_top');
            link.textContent = source.title;
            item.appendChild(link);
            list.appendChild(item);
        }

        wrapper.hidden = false;
    };

    const setStatus = (statusEl, message) => {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = message || '';
        statusEl.hidden = !message;
    };

    const applyAnswerPayload = (container, answerEl, statusEl, payload) => {
        if (payload.answer) {
            answerEl.textContent = payload.answer;
        }
        renderSources(container, payload.sources);
        updateHistoryField(container, payload.history);
        setStatus(statusEl, '');
    };

    const fetchAnswerFallback = async (container, answerEl, statusEl, requestBody, labels) => {
        setStatus(statusEl, labels.loading);
        const response = await fetch(ANSWER_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(requestBody),
        });

        if (!response.ok) {
            throw new Error(`Answer request failed with status ${response.status}`);
        }

        const payload = await response.json();
        applyAnswerPayload(container, answerEl, statusEl, payload);
    };

    const initStreamContainer = async (container) => {
        if (container.dataset.searchStreamInitialized) {
            return;
        }
        container.dataset.searchStreamInitialized = '1';

        const query = container.dataset.searchStreamQuery || '';
        const type = container.dataset.searchStreamType || '';
        const history = container.dataset.searchStreamHistory || '';
        const language = container.dataset.searchStreamLanguage || '';
        const context = parseContext(container.dataset.searchStreamContext || '[]');
        const answerEl = container.querySelector('[data-search-stream-answer]');
        const statusEl = container.querySelector('[data-search-stream-status]');
        const labels = labelsFor(container);

        if (!query || !answerEl) {
            return;
        }

        const requestBody = { query, type, history, language, context };
        setStatus(statusEl, statusEl?.textContent?.trim() || labels.loading);

        let receivedToken = false;
        let settled = false;
        let timeoutId = null;

        const failOverToAnswer = async () => {
            if (settled) {
                return;
            }
            settled = true;
            if (timeoutId !== null) {
                clearTimeout(timeoutId);
            }
            try {
                await fetchAnswerFallback(container, answerEl, statusEl, requestBody, labels);
            } catch {
                setStatus(statusEl, labels.error);
            }
        };

        timeoutId = setTimeout(() => {
            if (!receivedToken) {
                failOverToAnswer();
            }
        }, TOKEN_TIMEOUT_MS);

        try {
            const response = await fetch(STREAM_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                },
                credentials: 'same-origin',
                body: JSON.stringify(requestBody),
            });

            if (!response.ok || !response.body) {
                throw new Error(`Stream request failed with status ${response.status}`);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (!settled) {
                const { value, done } = await reader.read();
                if (done) {
                    break;
                }

                buffer += decoder.decode(value, { stream: true });
                buffer = parseEvents(buffer, (eventName, payload) => {
                    if (settled) {
                        return;
                    }

                    if (eventName === 'status') {
                        setStatus(statusEl, statusMessage(labels, payload));
                    } else if (eventName === 'token') {
                        receivedToken = true;
                        if (timeoutId !== null) {
                            clearTimeout(timeoutId);
                            timeoutId = null;
                        }
                        answerEl.textContent += payload.token;
                        setStatus(statusEl, '');
                    } else if (eventName === 'done') {
                        settled = true;
                        if (timeoutId !== null) {
                            clearTimeout(timeoutId);
                        }
                        if (!answerEl.textContent && payload.answer) {
                            answerEl.textContent = payload.answer;
                        }
                        renderSources(container, payload.sources);
                        updateHistoryField(container, payload.history);
                        setStatus(statusEl, '');
                    } else if (eventName === 'error') {
                        failOverToAnswer();
                    }
                });
            }

            if (!settled && !answerEl.textContent) {
                await failOverToAnswer();
            } else if (!settled) {
                settled = true;
                if (timeoutId !== null) {
                    clearTimeout(timeoutId);
                }
                setStatus(statusEl, '');
            }
        } catch {
            await failOverToAnswer();
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
