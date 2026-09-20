document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-confirm]').forEach((element) => {
        element.addEventListener('click', (event) => {
            const message = element.dataset.confirm || 'Are you sure?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('.live-search').forEach((wrapper) => {
        const input = wrapper.querySelector('.live-search-input');
        const hidden = wrapper.querySelector('.live-search-value');
        const menu = wrapper.querySelector('.live-search-menu');
        const options = Array.from(wrapper.querySelectorAll('.live-search-option'));
        const empty = wrapper.querySelector('.live-search-empty');

        if (!input || !hidden || !menu) return;

        let activeIndex = -1;

        const visibleOptions = () => options.filter((option) => !option.classList.contains('d-none'));

        const clearTargets = () => {
            const priceTargetId = wrapper.dataset.priceTarget;
            const qtyTargetId = wrapper.dataset.qtyTarget;
            const stockHintTargetId = wrapper.dataset.stockHintTarget;

            if (priceTargetId) {
                const priceTarget = document.getElementById(priceTargetId);
                if (priceTarget) priceTarget.value = '';
            }
            if (qtyTargetId) {
                const qtyTarget = document.getElementById(qtyTargetId);
                if (qtyTarget) qtyTarget.removeAttribute('max');
            }
            if (stockHintTargetId) {
                const hintTarget = document.getElementById(stockHintTargetId);
                if (hintTarget) hintTarget.textContent = 'Select an item / selling price.';
            }
        };

        const applySelectionTargets = (option) => {
            const priceTargetId = wrapper.dataset.priceTarget;
            const qtyTargetId = wrapper.dataset.qtyTarget;
            const stockHintTargetId = wrapper.dataset.stockHintTarget;

            if (priceTargetId) {
                const priceTarget = document.getElementById(priceTargetId);
                if (priceTarget) priceTarget.value = option.dataset.price || '';
            }

            if (qtyTargetId) {
                const qtyTarget = document.getElementById(qtyTargetId);
                const stock = parseInt(option.dataset.stock || '0', 10);
                if (qtyTarget && Number.isFinite(stock)) {
                    qtyTarget.max = String(stock);
                    const currentQty = parseInt(qtyTarget.value || '1', 10);
                    if (currentQty > stock) qtyTarget.value = String(stock);
                    if (currentQty < 1 && stock > 0) qtyTarget.value = '1';
                }
            }

            if (stockHintTargetId) {
                const hintTarget = document.getElementById(stockHintTargetId);
                if (hintTarget) {
                    const label = wrapper.dataset.stockLabel || 'Available stock';
                    hintTarget.textContent = label + ': ' + (option.dataset.stock || '0');
                }
            }
        };

        const setActive = (index) => {
            const visible = visibleOptions();
            visible.forEach((option) => option.classList.remove('active'));
            if (!visible.length) {
                activeIndex = -1;
                return;
            }
            activeIndex = Math.max(0, Math.min(index, visible.length - 1));
            visible[activeIndex].classList.add('active');
            visible[activeIndex].scrollIntoView({ block: 'nearest' });
        };

        const filterOptions = () => {
            const query = input.value.trim().toLowerCase();
            let shown = 0;

            options.forEach((option) => {
                const haystack = (option.dataset.search || option.textContent || '').toLowerCase();
                const matches = query === '' || haystack.includes(query);
                option.classList.toggle('d-none', !matches);
                if (matches) shown++;
                option.classList.remove('active');
            });

            if (empty) empty.classList.toggle('d-none', shown !== 0);
            activeIndex = -1;
            menu.classList.add('show');
        };

        const selectOption = (option) => {
            if (!option) return;
            hidden.value = option.dataset.value || '';
            input.value = option.dataset.label || option.textContent.trim();
            input.setCustomValidity('');
            options.forEach((item) => item.classList.remove('active'));
            menu.classList.remove('show');
            activeIndex = -1;
            applySelectionTargets(option);

            wrapper.dispatchEvent(new CustomEvent('live-search:selected', {
                bubbles: true,
                detail: { ...option.dataset }
            }));
        };

        input.addEventListener('focus', filterOptions);
        input.addEventListener('click', filterOptions);
        input.addEventListener('input', () => {
            hidden.value = '';
            input.setCustomValidity('');
            clearTargets();
            filterOptions();
        });

        const form = input.closest('form');
        if (form) {
            form.addEventListener('submit', (event) => {
                if (!hidden.value) {
                    event.preventDefault();
                    input.setCustomValidity('Please select an item from the search results.');
                    input.reportValidity();
                    filterOptions();
                    input.focus();
                }
            });
        }

        input.addEventListener('keydown', (event) => {
            const visible = visibleOptions();

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (!menu.classList.contains('show')) filterOptions();
                setActive(activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (!menu.classList.contains('show')) filterOptions();
                setActive(activeIndex <= 0 ? visible.length - 1 : activeIndex - 1);
            } else if (event.key === 'Enter' && menu.classList.contains('show')) {
                if (activeIndex >= 0 && visible[activeIndex]) {
                    event.preventDefault();
                    selectOption(visible[activeIndex]);
                } else if (visible.length === 1) {
                    event.preventDefault();
                    selectOption(visible[0]);
                }
            } else if (event.key === 'Escape') {
                menu.classList.remove('show');
                activeIndex = -1;
            }
        });

        options.forEach((option) => {
            option.addEventListener('click', () => selectOption(option));
        });

        document.addEventListener('click', (event) => {
            if (!wrapper.contains(event.target)) {
                menu.classList.remove('show');
                activeIndex = -1;
            }
        });
    });
});
