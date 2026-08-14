// This file is part of Moodle - http://moodle.org/.

/**
 * Adds keyboard-accessible tabs to the learning indicators dashboard.
 *
 * @module report_indicadoresdocentes/dashboard
 */

const dashboardSelector = '[data-region="learning-dashboard"]';
const tabSelector = '[data-dashboard-tab]';
const panelSelector = '[data-dashboard-panel]';
const tableToggleSelector = '[data-table-toggle]';
const activityTypeSelector = '[data-activity-type-select]';
const contextTableSelector = '[data-dashboard-context]';
const progressTotalSelector = '[data-progress-total]';

/**
 * Adds the completed + pending total to Moodle's asynchronously generated chart table.
 *
 * @param {HTMLElement} container Progress chart container.
 */
const addProgressTotal = (container) => {
    const table = container.querySelector('.chart-output-htmltable');
    if (!table || table.querySelector('[data-progress-total-row]')) {
        return;
    }

    const row = document.createElement('tr');
    row.dataset.progressTotalRow = 'true';
    const heading = document.createElement('th');
    heading.scope = 'row';
    heading.textContent = container.dataset.progressTotalLabel;
    const value = document.createElement('td');
    value.textContent = container.dataset.progressTotal;
    row.append(heading, value);
    table.append(row);
};

/**
 * Activates one dashboard chart.
 *
 * @param {HTMLElement} dashboard Dashboard root.
 * @param {HTMLElement} selectedTab Tab to activate.
 */
const activateTab = (dashboard, selectedTab) => {
    const selectedKey = selectedTab.dataset.dashboardTab;

    dashboard.querySelectorAll(tabSelector).forEach((tab) => {
        const active = tab === selectedTab;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.setAttribute('tabindex', active ? '0' : '-1');
    });

    dashboard.querySelectorAll(panelSelector).forEach((panel) => {
        const active = panel.dataset.dashboardPanel === selectedKey;
        panel.hidden = !active;
        panel.classList.toggle('is-active', active);
    });

    document.querySelectorAll(contextTableSelector).forEach((section) => {
        const visibleTabs = section.dataset.dashboardContext.split(',');
        section.hidden = !visibleTabs.includes(selectedKey);
    });

    window.dispatchEvent(new Event('resize'));
};

/**
 * Initialises all learning dashboards on the page.
 */
export const init = () => {
    document.querySelectorAll(progressTotalSelector).forEach((container) => {
        addProgressTotal(container);
        const observer = new MutationObserver(() => addProgressTotal(container));
        observer.observe(container, {childList: true, subtree: true});
    });

    document.querySelectorAll(dashboardSelector).forEach((dashboard) => {
        const tabs = Array.from(dashboard.querySelectorAll(tabSelector));

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => activateTab(dashboard, tab));
            tab.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                    return;
                }

                event.preventDefault();
                let nextIndex = index;
                if (event.key === 'ArrowRight') {
                    nextIndex = (index + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft') {
                    nextIndex = (index - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = tabs.length - 1;
                }

                activateTab(dashboard, tabs[nextIndex]);
                tabs[nextIndex].focus();
            });
        });

        const activeTab = tabs.find((tab) => tab.classList.contains('is-active')) || tabs[0];
        if (activeTab) {
            activateTab(dashboard, activeTab);
        }
    });

    document.querySelectorAll(tableToggleSelector).forEach((button) => {
        const panel = document.querySelector(`[data-table-panel="${button.dataset.tableToggle}"]`);
        if (!panel) {
            return;
        }

        button.addEventListener('click', () => {
            const expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            button.textContent = expanded ? button.dataset.showLabel : button.dataset.hideLabel;
            panel.hidden = expanded;
        });
    });

    document.querySelectorAll(activityTypeSelector).forEach((select) => {
        const deliveryPanel = select.closest('[data-dashboard-panel]');
        if (!deliveryPanel) {
            return;
        }
        select.addEventListener('change', () => {
            deliveryPanel.querySelectorAll('[data-activity-type-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.activityTypePanel !== select.value;
            });
            window.dispatchEvent(new Event('resize'));
        });
    });
};
