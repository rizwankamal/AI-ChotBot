(() => {
  const root = document.getElementById('shoppulse-chat-root');
  if (!root || root.dataset.spReady === '1') return;
  root.dataset.spReady = '1';

  const shop = root.dataset.shop;
  const apiUrl = root.dataset.apiUrl;
  const title = root.dataset.title || 'Shopping Assistant';

  root.innerHTML = `
    <div class="sp-panel" id="sp-panel" aria-hidden="true">
      <div class="sp-header">
        <div class="sp-brand">
          <div class="sp-avatar" aria-hidden="true">AI</div>
          <div>
            <div class="sp-header-title">${title}</div>
            <div class="sp-header-sub">Styled picks · live stock</div>
          </div>
        </div>
        <button type="button" class="sp-header-close" id="sp-close" aria-label="Close chat">×</button>
      </div>
      <div class="sp-messages" id="sp-messages"></div>
      <form class="sp-form" id="sp-form">
        <input id="sp-input" type="text" placeholder="Try: woolen clothes, hoodie…" autocomplete="off" required />
        <button type="submit">Send</button>
      </form>
    </div>
    <button type="button" class="sp-launcher" id="sp-launcher">
      <span class="sp-launcher-dot" aria-hidden="true"></span>
      Shop Assistant
    </button>
  `;

  const panel = root.querySelector('#sp-panel');
  const launcher = root.querySelector('#sp-launcher');
  const closer = root.querySelector('#sp-close');
  const form = root.querySelector('#sp-form');
  const input = root.querySelector('#sp-input');
  const messages = root.querySelector('#sp-messages');

  const setOpen = (open) => {
    panel.classList.toggle('is-open', open);
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (open) input.focus();
  };

  launcher.addEventListener('click', () => setOpen(!panel.classList.contains('is-open')));
  closer.addEventListener('click', () => setOpen(false));

  const escapeHtml = (text) =>
    String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

  const cleanBotText = (text) =>
    text
      .replace(/\*\*([^*]+)\*\*/g, '$1')
      .replace(/\n{3,}/g, '\n\n')
      .trim();

  const formatBotHtml = (text) => {
    const cleaned = cleanBotText(text);
    const placeholders = [];
    const withPlaceholders = cleaned.replace(
      /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
      (_, label, url) => {
        const token = `__SPLINK${placeholders.length}__`;
        placeholders.push(
          `<a class="sp-link" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(label)}</a>`,
        );
        return token;
      },
    );

    let html = escapeHtml(withPlaceholders);
    html = html.replace(
      /(https?:\/\/[^\s<]+)/g,
      '<a class="sp-link" href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
    );
    placeholders.forEach((anchor, index) => {
      html = html.replace(`__SPLINK${index}__`, anchor);
    });
    return html;
  };

  const money = (price) => {
    const n = Number(price);
    if (Number.isFinite(n)) {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
      }).format(n);
    }
    return `$${price}`;
  };

  const appendMessage = (text, who) => {
    const el = document.createElement('div');
    el.className = `sp-msg ${who}`;
    if (who === 'bot') {
      el.innerHTML = formatBotHtml(text);
      el.dataset.raw = text;
    } else {
      el.textContent = text;
    }
    messages.appendChild(el);
    messages.scrollTop = messages.scrollHeight;
    return el;
  };

  const setBotText = (el, text) => {
    el.dataset.raw = text;
    el.innerHTML = formatBotHtml(text);
    messages.scrollTop = messages.scrollHeight;
  };

  const renderProducts = (items) => {
    if (!Array.isArray(items) || items.length === 0) return;

    const wrap = document.createElement('div');
    wrap.className = 'sp-products';
    const seen = new Set();

    items.forEach((item) => {
      const url = item?.url;
      const titleText = item?.title || 'Product';
      if (!url || seen.has(url)) return;
      seen.add(url);

      const stock = Number(item.inventory_count ?? 0);
      const inStock = stock > 0;
      const card = document.createElement('a');
      card.className = 'sp-card';
      card.href = url;
      card.target = '_blank';
      card.rel = 'noopener noreferrer';

      const imgHtml = item.image_url
        ? `<img class="sp-card-image" src="${escapeHtml(item.image_url)}" alt="${escapeHtml(titleText)}" loading="lazy" />`
        : `<div class="sp-card-image placeholder">Look</div>`;

      card.innerHTML = `
        ${imgHtml}
        <div class="sp-card-body">
          <div class="sp-card-title">${escapeHtml(titleText)}</div>
          <div class="sp-card-meta">
            <span class="sp-price">${escapeHtml(money(item.price))}</span>
            <span class="sp-stock ${inStock ? '' : 'out'}">${inStock ? `${stock} in stock` : 'Sold out'}</span>
          </div>
          <div class="sp-card-cta">View product →</div>
        </div>
      `;
      wrap.appendChild(card);
    });

    if (wrap.childElementCount > 0) {
      messages.appendChild(wrap);
      messages.scrollTop = messages.scrollHeight;
    }
  };

  const renderContentLinks = (items) => {
    if (!Array.isArray(items) || items.length === 0) return;

    const groups = { page: [], article: [], policy: [] };
    const seen = new Set();

    items.forEach((item) => {
      const url = item?.url;
      const titleText = item?.title || 'Read more';
      if (!url || seen.has(url)) return;
      seen.add(url);

      const type = String(item.source_type || 'page');
      if (type === 'article' || type === 'blog') groups.article.push({ url, titleText, label: 'Blog' });
      else if (type === 'policy') groups.policy.push({ url, titleText, label: 'Policy' });
      else groups.page.push({ url, titleText, label: 'Page' });
    });

    const wrap = document.createElement('div');
    wrap.className = 'sp-content-links';

    const appendGroup = (heading, rows) => {
      if (!rows.length) return;
      const section = document.createElement('div');
      section.className = 'sp-content-group';
      section.innerHTML = `<div class="sp-content-heading">${escapeHtml(heading)}</div>`;
      rows.forEach((row) => {
        const link = document.createElement('a');
        link.className = 'sp-content-link';
        link.href = row.url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.innerHTML = `
          <span class="sp-content-type">${escapeHtml(row.label)}</span>
          <span class="sp-content-title">${escapeHtml(row.titleText)}</span>
          <span class="sp-content-cta">Open →</span>
        `;
        section.appendChild(link);
      });
      wrap.appendChild(section);
    };

    appendGroup('Pages', groups.page);
    appendGroup('Blogs', groups.article);
    appendGroup('Policies', groups.policy);

    if (wrap.childElementCount > 0) {
      messages.appendChild(wrap);
      messages.scrollTop = messages.scrollHeight;
    }
  };

  const buildRequestUrl = (base) => {
    try {
      const url = new URL(base, window.location.origin);
      if (url.hostname.includes('ngrok')) {
        url.searchParams.set('ngrok-skip-browser-warning', 'true');
      }
      return url.toString();
    } catch (_) {
      return base;
    }
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const message = input.value.trim();
    if (!message) return;

    appendMessage(message, 'user');
    input.value = '';
    const bot = appendMessage('Finding pieces…', 'bot');
    let raw = '';
    let gotToken = false;
    let sawDone = false;
    let sawError = false;

    const applySsePayload = (payload) => {
      if (payload.type === 'token') {
        if (!gotToken) {
          raw = '';
          gotToken = true;
        }
        raw += payload.content || '';
        setBotText(bot, raw);
      } else if (payload.type === 'products') {
        renderProducts(payload.items || []);
      } else if (payload.type === 'content_links') {
        renderContentLinks(payload.items || []);
      } else if (payload.type === 'error') {
        sawError = true;
        raw = payload.content || 'Sorry, I could not complete that request right now.';
        setBotText(bot, raw);
      } else if (payload.type === 'done') {
        sawDone = true;
      }
    };

    const flushSseBuffer = (chunk, carry) => {
      const merged = carry + chunk;
      const parts = merged.split('\n\n');
      const rest = parts.pop() || '';

      for (const part of parts) {
        const line = part.trim();
        if (!line.startsWith('data: ')) continue;
        try {
          applySsePayload(JSON.parse(line.slice(6)));
        } catch (_) {
          /* ignore malformed SSE frame */
        }
      }

      return rest;
    };

    try {
      const response = await fetch(buildRequestUrl(apiUrl), {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
        body: JSON.stringify({ message, shop }),
      });

      if (!response.ok || !response.body) {
        setBotText(bot, 'Sorry, the assistant is unavailable right now.');
        return;
      }

      const contentType = (response.headers.get('content-type') || '').toLowerCase();
      if (contentType.includes('text/html')) {
        setBotText(bot, 'Tunnel blocked the API (use cloudflared, not free ngrok).');
        return;
      }

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer = flushSseBuffer(decoder.decode(value, { stream: true }), buffer);
      }

      buffer = flushSseBuffer(decoder.decode(), buffer);
      if (buffer.trim().startsWith('data: ')) {
        try {
          applySsePayload(JSON.parse(buffer.trim().slice(6)));
        } catch (_) {
          /* ignore trailing partial frame */
        }
      }

      if (sawError) {
        setBotText(bot, raw);
      } else if (!sawDone) {
        setBotText(bot, 'Sorry, the reply was cut off. Please try again.');
      } else if (!gotToken) {
        setBotText(bot, 'No response received.');
      } else {
        setBotText(bot, raw);
      }
    } catch (error) {
      setBotText(
        bot,
        'Connection error. Update ShopPulse app URL to your live tunnel, then refresh.',
      );
      console.error('ShopPulse chat failed', { apiUrl, error });
    }
  });
})();
