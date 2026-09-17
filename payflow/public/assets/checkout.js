/*!
 * PayFlow · checkout.js — 一行嵌入的结账 SDK
 *
 * 用法 A（自动按钮）：
 *   <script src="https://payflow.nownexts.com/checkout.js" data-product="prod_xxx"></script>
 * 用法 B（指定挂载点）：
 *   <script src="https://payflow.nownexts.com/checkout.js"
 *           data-product="prod_xxx" data-target="#buy-slot" data-label="立即购买"></script>
 * 用法 C（JS 调用）：
 *   PayFlow.open({ product: 'prod_xxx' })
 *
 * 支持 data-mode="redirect" 直接跳转托管收银台（不走弹窗）。
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var origin = script ? new URL(script.src, window.location.href).origin : window.location.origin;
  var base = script ? script.src.replace(/\/checkout\.js.*$/, '') : origin;

  var DEFAULT_LABEL = '立即购买';

  function css(el, styles) {
    for (var k in styles) el.style[k] = styles[k];
  }

  function buildButton(label) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = label;
    css(btn, {
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      height: '46px',
      padding: '0 22px',
      borderRadius: '12px',
      border: '1px solid transparent',
      background: 'oklch(52% .17 258)',
      color: '#fff',
      fontSize: '15px',
      fontWeight: '650',
      cursor: 'pointer',
      fontFamily: 'inherit',
      transition: 'transform .2s cubic-bezier(.32,.72,0,1), background .2s',
    });
    btn.addEventListener('mouseenter', function () { btn.style.background = 'oklch(46% .17 258)'; });
    btn.addEventListener('mouseleave', function () { btn.style.background = 'oklch(52% .17 258)'; });
    return btn;
  }

  function openModal(product, label) {
    var overlay = document.createElement('div');
    css(overlay, {
      position: 'fixed', inset: '0', zIndex: '2147483000',
      background: 'oklch(10% 0 0 / .45)', backdropFilter: 'blur(4px)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '20px',
    });

    var frame = document.createElement('iframe');
    frame.src = base + '/checkout?product=' + encodeURIComponent(product) + '&embed=1';
    frame.title = label || 'PayFlow 结账';
    css(frame, {
      width: 'min(560px, 100%)', height: 'min(760px, 92vh)',
      border: '0', borderRadius: '22px', background: '#fff',
      boxShadow: '0 24px 60px -24px oklch(30% .04 80 / .4)',
    });

    overlay.appendChild(frame);
    document.body.appendChild(overlay);

    var close = function () { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); };
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    window.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

    window.addEventListener('message', function (e) {
      if (e.origin !== origin) return;
      var data = e.data || {};
      if (data.type === 'payflow:close') close();
      if (data.type === 'payflow:paid') {
        if (data.redirect_url) window.location.href = data.redirect_url;
        else close();
      }
    });

    return close;
  }

  function open(opts) {
    opts = opts || {};
    var product = opts.product;
    if (!product) { console.error('[PayFlow] 缺少 product'); return; }
    if (script && script.getAttribute('data-mode') === 'redirect') {
      window.location.href = base + '/checkout?product=' + encodeURIComponent(product);
      return;
    }
    return openModal(product, opts.label);
  }

  function mount() {
    var nodes = document.querySelectorAll('[data-payflow-product]');
    for (var i = 0; i < nodes.length; i++) {
      (function (node) {
        if (node.getAttribute('data-payflow-ready') === '1') return;
        var product = node.getAttribute('data-payflow-product');
        var label = node.getAttribute('data-payflow-label') || DEFAULT_LABEL;
        var btn = buildButton(label);
        btn.addEventListener('click', function () { open({ product: product, label: label }); });
        node.appendChild(btn);
        node.setAttribute('data-payflow-ready', '1');
      })(nodes[i]);
    }

    if (script) {
      var product = script.getAttribute('data-product');
      if (product) {
        var targetSel = script.getAttribute('data-target');
        var label = script.getAttribute('data-label') || DEFAULT_LABEL;
        var host = targetSel ? document.querySelector(targetSel) : null;
        var wrap = document.createElement('span');
        wrap.setAttribute('data-payflow-product', product);
        wrap.setAttribute('data-payflow-label', label);
        if (host) {
          host.appendChild(wrap);
        } else {
          script.parentNode.insertBefore(wrap, script);
        }
        mount();
      }
    }
  }

  window.PayFlow = { open: open, origin: origin, base: base };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
