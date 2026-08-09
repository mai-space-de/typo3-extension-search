(() => {
    const ENDPOINT = '/api/search/suggest';
    const MIN_CHARS = 2;
    const DEBOUNCE_MS = 200;

    const debounce = (fn, delay) => {
        let timer = null;
        return (...args) => {
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(() => fn(...args), delay);
        };
    };

    const hideList = (list) => {
        list.hidden = true;
        list.innerHTML = '';
    };

    const renderSuggestions = (list, suggestions, input) => {
        list.innerHTML = '';

        if (suggestions.length === 0) {
            list.hidden = true;
            return;
        }

        suggestions.forEach((suggestion) => {
            const item = document.createElement('li');
            item.className = 'search-suggest__item';
            item.setAttribute('role', 'option');

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'search-suggest__button';
            button.textContent = suggestion;
            button.addEventListener('click', () => {
                input.value = suggestion;
                hideList(list);
                input.closest('form')?.requestSubmit();
            });

            item.appendChild(button);
            list.appendChild(item);
        });

        list.hidden = false;
    };

    const initSuggestContainer = (container) => {
        if (container.dataset.searchSuggestInitialized) {
            return;
        }
        container.dataset.searchSuggestInitialized = '1';

        const input = container.querySelector('[data-search-suggest-input]');
        const list = container.querySelector('[data-search-suggest-list]');

        if (!input || !list) {
            return;
        }

        const fetchSuggestions = debounce(async (query) => {
            if (query.trim().length < MIN_CHARS) {
                hideList(list);
                return;
            }

            try {
                const response = await fetch(`${ENDPOINT}?q=${encodeURIComponent(query)}`);
                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                renderSuggestions(list, Array.isArray(payload.suggestions) ? payload.suggestions : [], input);
            } catch {
                // Network/parse failure — leave whatever suggestions are currently shown.
            }
        }, DEBOUNCE_MS);

        input.addEventListener('input', () => {
            fetchSuggestions(input.value);
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideList(list);
            }
        });

        document.addEventListener('click', (event) => {
            if (!container.contains(event.target)) {
                hideList(list);
            }
        });
    };

    const scan = (root) => {
        root.querySelectorAll('[data-search-suggest]').forEach((container) => {
            initSuggestContainer(container);
        });
    };

    scan(document);

    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) {
                    return;
                }

                if (node.matches('[data-search-suggest]')) {
                    initSuggestContainer(node);
                } else {
                    scan(node);
                }
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
