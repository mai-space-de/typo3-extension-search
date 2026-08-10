const POLL_INTERVAL_MS = 2000;

const STATUS_LABEL_CLASS = {
  pending: 'label label-warning',
  processing: 'label label-info',
  done: 'label label-success',
  failed: 'label label-danger',
};

const STATUS_LABELS = {
  pending: 'Pending',
  processing: 'Processing',
  done: 'Done',
  failed: 'Failed',
};

class QueuePoller {
  #root;
  #statusUrl;
  #intervalId = null;
  #busy = false;

  constructor(root) {
    this.#root = root;
    this.#statusUrl = root.dataset.queueStatusUrl || '';
    if (!this.#statusUrl) {
      return;
    }
    this.#start();
  }

  #start() {
    this.#intervalId = window.setInterval(() => {
      void this.#refresh();
    }, POLL_INTERVAL_MS);
  }

  async #refresh() {
    if (this.#busy || document.hidden) {
      return;
    }
    this.#busy = true;
    try {
      const response = await fetch(this.#statusUrl, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      if (!response.ok) {
        return;
      }
      const data = await response.json();
      const counts = data.counts || {};
      const percentages = data.percentages || {};
      const total = typeof data.total === 'number'
        ? data.total
        : Object.values(counts).reduce((sum, value) => sum + Number(value || 0), 0);

      this.#updateCounts(counts);
      this.#updateProgress(counts, percentages, total);
      this.#updateJobs(Array.isArray(data.jobs) ? data.jobs : []);
    } catch {
      // Keep polling; transient network errors should not stop the live view.
    } finally {
      this.#busy = false;
    }
  }

  #updateCounts(counts) {
    for (const status of ['pending', 'processing', 'done', 'failed']) {
      if (!Object.prototype.hasOwnProperty.call(counts, status)) {
        continue;
      }
      this.#root.querySelectorAll(`[data-queue-count="${status}"]`).forEach((el) => {
        el.textContent = String(counts[status]);
      });
    }
  }

  #updateProgress(counts, percentages, total) {
    const totalEl = this.#root.querySelector('[data-queue-progress-total]');
    if (totalEl) {
      totalEl.textContent = String(total);
    }

    for (const status of ['pending', 'processing', 'done', 'failed']) {
      const segment = this.#root.querySelector(`[data-queue-segment="${status}"]`);
      if (!segment) {
        continue;
      }
      const count = Number(counts[status] || 0);
      const percent = Number(percentages[status] || 0);
      segment.style.flexGrow = String(count);
      segment.title = `${STATUS_LABELS[status] || status}: ${count} (${percent}%)`;
    }
  }

  #updateJobs(jobs) {
    const table = this.#root.querySelector('[data-queue-jobs-table]');
    const tbody = this.#root.querySelector('[data-queue-jobs]');
    const empty = this.#root.querySelector('[data-queue-jobs-empty]');
    if (!tbody || !table || !empty) {
      return;
    }

    if (jobs.length === 0) {
      tbody.replaceChildren();
      table.hidden = true;
      empty.hidden = false;
      return;
    }

    const fragment = document.createDocumentFragment();
    for (const job of jobs) {
      fragment.appendChild(this.#createJobRow(job));
    }
    tbody.replaceChildren(fragment);
    table.hidden = false;
    empty.hidden = true;
  }

  #createJobRow(job) {
    const tr = document.createElement('tr');
    tr.append(
      this.#createCodeCell(job.tableName ?? ''),
      this.#createTextCell(String(job.recordUid ?? '')),
      this.#createLabelCell(job.operation ?? '', 'label label-default'),
      this.#createLabelCell(
        job.status ?? '',
        STATUS_LABEL_CLASS[job.status] || 'label label-default',
      ),
      this.#createTextCell(job.queuedDate ?? ''),
      this.#createTextCell(job.processedDate ?? ''),
      this.#createTextCell(job.errorMessage ?? ''),
    );
    return tr;
  }

  #createTextCell(text) {
    const td = document.createElement('td');
    td.textContent = text;
    return td;
  }

  #createCodeCell(text) {
    const td = document.createElement('td');
    const code = document.createElement('code');
    code.textContent = text;
    td.appendChild(code);
    return td;
  }

  #createLabelCell(text, className) {
    const td = document.createElement('td');
    const span = document.createElement('span');
    span.className = className;
    span.textContent = text;
    td.appendChild(span);
    return td;
  }
}

export function initialize() {
  document.querySelectorAll('[data-queue-poll]').forEach((el) => {
    if (el.dataset.queuePollInitialized === '1') {
      return;
    }
    el.dataset.queuePollInitialized = '1';
    new QueuePoller(el);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initialize);
} else {
  initialize();
}
