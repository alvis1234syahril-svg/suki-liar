/*
 * Senja di Akropolis · logika halaman
 * 1. Render konten dari window.PORTOFOLIO (js/data.js)
 * 2. Parallax hero (gulir + gerak kursor) dan elemen [data-depth]
 * 3. Galeri karya yang bergeser ke samping saat digulir (layar lebar)
 * 4. Navigasi: menu mobile, status aktif, latar solid setelah hero
 * 5. Kemunculan elemen [data-reveal]
 */
(function () {
  "use strict";

  var D = window.PORTOFOLIO;
  var doc = document;
  var $ = function (sel, root) { return (root || doc).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); };
  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  var finePointer = window.matchMedia("(pointer: fine)");
  var wide = window.matchMedia("(min-width: 900px) and (min-height: 600px)");

  var esc = function (s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  };
  // Tandai placeholder supaya mudah ditemukan saat mengganti konten.
  var rich = function (s) {
    return esc(s).replace(/\[(GANTI|PERIKSA|Teknologi)\]/g, '<mark class="ganti">[$1]</mark>');
  };
  var get = function (obj, path) {
    return path.split(".").reduce(function (o, k) { return o == null ? undefined : o[k]; }, obj);
  };
  var hasUrl = function (l) { return l && typeof l.url === "string" && l.url.trim() !== ""; };
  var NUMERALS = ["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X"];
  // Benda di atas alas batu untuk tiap pilar keahlian (latar transparan, 640 px).
  var ICONS = {
    "helm": "assets/foto/pilar-helm.webp",
    "burung-hantu": "assets/foto/pilar-burung-hantu.webp",
    "lira": "assets/foto/pilar-lira.webp",
    "gulungan": "assets/foto/pilar-gulungan.webp"
  };
  var newTab = '<span class="visually-hidden"> (tab baru)</span>';
  var LOG_LABEL = { terlihat: "terlihat di log", ambigu: "ambigu, IP dipakai beberapa aset", belum: "belum terlihat di log" };
  var PORT_LABEL = { normal: "normal (admin up, link up)", periksa: "perlu diperiksa (admin up, link down)", nonaktif: "dinonaktifkan (admin down)" };

  /* ---------------- Gambar karya + demo topologi di atas ukiran ---------------- */
  function mediaHTML(w) {
    var img = '<img class="work__shot" src="' + esc(w.gambar) + '" alt="' + esc(w.gambarAlt || "Tangkapan layar " + w.judul) + '" loading="lazy">';
    var t = w.topologi;
    if (!t || !(t.simpul || []).length) return '<div class="work__media">' + img + "</div>";
    var byId = {};
    t.simpul.forEach(function (n) { byId[n.id] = n; });
    var edges = (t.tautan || []).filter(function (e) { return byId[e[0]] && byId[e[1]]; }).map(function (e) {
      var a = byId[e[0]], b = byId[e[1]];
      var tree = a.induk === b.id || b.induk === a.id;
      return '<line class="topo__edge ' + (tree ? "is-tree" : "is-backup") + '" data-a="' + esc(a.id) + '" data-b="' + esc(b.id) +
        '" x1="' + a.x + '" y1="' + a.y + '" x2="' + b.x + '" y2="' + b.y + '"/>';
    }).join("");
    var nodes = t.simpul.map(function (n) {
      return '<g class="topo__node is-' + esc(n.log) + '" data-id="' + esc(n.id) + '" data-traffic="' + esc(n.traffic) +
        '" tabindex="0" role="button" aria-pressed="false" aria-label="' + esc(n.nama + ", " + n.tipe + ", " + (LOG_LABEL[n.log] || n.log)) + '">' +
        '<circle class="topo__hit" cx="' + n.x + '" cy="' + n.y + '" r="46"/>' +
        '<circle class="topo__ring" cx="' + n.x + '" cy="' + n.y + '" r="22"/>' +
        (n.port && n.port !== "normal" ? '<g class="topo__warn"><circle cx="' + (n.x + 24) + '" cy="' + (n.y - 24) + '" r="16"/><text x="' + (n.x + 24) + '" y="' + (n.y - 16) + '">!</text></g>' : "") +
        "</g>";
    }).join("");
    // Ukiran tambahan di permukaan batu yang kosong (koordinat gambar asli):
    // kiri = legenda status yang bisa diklik, kanan = kolom Doria sebagai meter beban traffic.
    var cv = function (inner) { return '<g class="cv-hi" transform="translate(2.5 2.5)">' + inner + '</g><g class="cv-lo">' + inner + "</g>"; };
    var count = { terlihat: 0, ambigu: 0, belum: 0, port: 0 };
    t.simpul.forEach(function (n) { if (count[n.log] != null) count[n.log]++; if (n.port && n.port !== "normal") count.port++; });
    var LX = 488;
    var icons = {
      terlihat: function (y) { return '<circle cx="' + LX + '" cy="' + y + '" r="24" stroke-width="6"/>'; },
      ambigu: function (y) { return '<circle cx="' + LX + '" cy="' + y + '" r="24" stroke-width="6" stroke-dasharray="8 7"/>'; },
      belum: function (y) { return '<circle cx="' + LX + '" cy="' + y + '" r="24" stroke-width="2.5"/>'; },
      port: function (y) { return '<circle cx="' + LX + '" cy="' + y + '" r="23" stroke-width="4"/><text x="' + LX + '" y="' + (y + 12) + '" class="cv-mark">!</text>'; }
    };
    var legend = [["terlihat", "terlihat di log"], ["ambigu", "ambigu"], ["belum", "belum terlihat di log"], ["port", "port perlu diperiksa"]].map(function (it, k) {
      var y = 232 + k * 105;
      return '<g class="topo__lg" data-filter="' + it[0] + '" tabindex="0" role="button" aria-pressed="false" aria-label="Saring simpul: ' + it[1] + ", " + count[it[0]] + ' aset">' +
        '<rect class="topo__hit" x="428" y="' + (y - 38) + '" width="120" height="104"/>' +
        cv(icons[it[0]](y) + '<text class="cv-num" x="' + LX + '" y="' + (y + 66) + '">' + count[it[0]] + "</text>") + "</g>";
    }).join("");
    var flutes = [1361, 1372, 1384, 1395].map(function (x) { return '<line x1="' + x + '" y1="238" x2="' + x + '" y2="570" stroke-width="3"/>'; }).join("");
    var meter = '<g class="topo__meter" role="img" aria-label="Beban traffic: jalankan simulasi atau pilih simpul">' +
      '<rect class="topo__meter-fill" x="1350" y="228" width="56" height="350"/>' +
      cv('<rect x="1338" y="200" width="80" height="16" stroke-width="4"/><path d="M1346 216 L1352 228 L1404 228 L1410 216" stroke-width="4"/>' +
        '<rect x="1350" y="228" width="56" height="350" stroke-width="4"/>' + flutes +
        '<rect x="1342" y="578" width="72" height="14" stroke-width="4"/><rect x="1334" y="592" width="88" height="14" stroke-width="4"/>') + "</g>";
    var stone = '<g class="topo__stone">' + legend + meter + "</g>";
    var defs = '<linearGradient id="topo-fill" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#E6CB85"/><stop offset="1" stop-color="#FFF4D6"/></linearGradient>';

    return '<div class="topo__bar"><span class="topo__badge">Demo · data contoh</span>' +
      '<button class="topo__btn" type="button" aria-pressed="false" data-topo-sim>Simulasikan traffic</button></div>' +
      '<div class="work__media">' + img +
      '<svg class="topo__svg" viewBox="0 0 ' + t.lebar + " " + t.tinggi + '" preserveAspectRatio="xMidYMid slice" role="group" aria-label="Diagram topologi interaktif, ' + t.simpul.length + ' simpul">' +
      '<defs><filter id="topo-glow" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="6" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>' + defs + "</defs>" +
      stone + '<g class="topo__edges">' + edges + '</g><g data-topo-packets></g><g class="topo__nodes">' + nodes + "</g></svg></div>" +
      '<div class="topo__tip" data-topo-tip hidden></div>';
  }

  function initTopo() {
    $$("[data-topo]").forEach(function (art) {
      var t = ((D.karya || [])[+art.getAttribute("data-topo")] || {}).topologi;
      if (!t) return;
      var card = art.closest(".work");
      var byId = {};
      t.simpul.forEach(function (n) { byId[n.id] = n; });
      var svg = $(".topo__svg", art), tip = $("[data-topo-tip]", art), btn = $("[data-topo-sim]", art);
      var packets = $("[data-topo-packets]", art), panel = $("[data-topo-panel]", card);
      var nodes = $$(".topo__node", art), edges = $$(".topo__edge", art), legend = $$(".topo__lg", art);
      var meter = $(".topo__meter", art), meterFill = $(".topo__meter-fill", art);
      var selected = null, filter = null;
      var NS = "http://www.w3.org/2000/svg";
      var LEVEL = { tinggi: 1, sedang: 0.6, rendah: 0.3, "tidak ada": 0 };
      var FILTER_LABEL = { terlihat: "Terlihat di log", ambigu: "Ambigu", belum: "Belum terlihat di log", port: "Port perlu diperiksa" };
      var avg = t.simpul.reduce(function (s, n) { return s + (LEVEL[n.traffic] || 0); }, 0) / t.simpul.length;
      var levelWord = function (v) { return v >= 0.8 ? "tinggi" : v >= 0.45 ? "sedang" : v > 0 ? "rendah" : "tidak ada"; };
      var row = function (k, v) { return "<dt>" + k + "</dt><dd>" + esc(v) + "</dd>"; };
      var chain = function (id) { var out = [], guard = 0; while (id && byId[id] && guard++ < 50) { out.push(id); id = byId[id].induk; } return out; };
      var matches = function (n, f) { return f === "port" ? !!n.port && n.port !== "normal" : n.log === f; };
      var isPinned = function () { return card.closest(".works").classList.contains("is-pinned"); };
      var hint = '<p class="topo__hint">Klik simpul pada ukiran, atau tekan Tab, untuk melihat detail aset dan jalurnya ke firewall.</p>' +
        '<p class="topo__legend"><span class="lg"></span>terlihat di log <span class="lg lg--dash"></span>ambigu <span class="lg lg--dim"></span>belum terlihat <span class="lg--warn">!</span> port perlu diperiksa</p>';

      // Kolom Doria di sisi kanan: terisi cahaya sesuai beban traffic simpul terpilih / rata-rata jaringan saat simulasi.
      var updateMeter = function () {
        var sim = art.classList.contains("is-sim");
        var v = selected ? (LEVEL[byId[selected].traffic] || 0) : (sim ? avg : 0);
        meterFill.style.transform = "scaleY(" + v.toFixed(3) + ")";
        meter.setAttribute("aria-label", selected ? "Beban traffic " + byId[selected].nama + ": " + byId[selected].traffic
          : sim ? "Beban traffic jaringan: " + levelWord(avg) : "Beban traffic: jalankan simulasi atau pilih simpul");
      };

      // Panel: di bawah gambar (ponsel); di galeri dipin menjadi kartu tepat di bawah prasasti
      // dengan panah ke simpul/legenda terpilih, sehingga ukiran tetap terlihat utuh.
      var place = function () {
        if (!isPinned()) { panel.hidden = false; panel.style.left = ""; panel.style.top = ""; return; }
        var anchor = selected ? $('.topo__node[data-id="' + selected + '"] .topo__ring', art)
          : filter ? $('.topo__lg[data-filter="' + filter + '"]', art) : null;
        if (!anchor) { panel.hidden = true; return; }
        panel.hidden = false;
        var cr = card.getBoundingClientRect(), ar = art.getBoundingClientRect(), r = anchor.getBoundingClientRect();
        var pw = panel.offsetWidth, cx = r.left + r.width / 2 - cr.left;
        var left = Math.max(10, Math.min(cx - pw / 2, cr.width - pw - 10));
        panel.style.left = left.toFixed(0) + "px";
        panel.style.top = (ar.bottom - cr.top + 12).toFixed(0) + "px";
        panel.style.setProperty("--caret-x", (cx - left).toFixed(0) + "px");
      };
      var closeBtn = '<button class="topo__close" type="button" aria-label="Tutup detail">×</button>';

      var renderPanel = function () {
        if (selected) {
          var n = byId[selected], parent = n.induk ? byId[n.induk] : null;
          panel.innerHTML = closeBtn + '<p class="topo__name">' + esc(n.nama) + "</p><dl>" +
            row("Tipe", n.tipe + " · " + n.ip) + row("Induk", parent ? parent.nama : "akar topologi") +
            row("Di log", LOG_LABEL[n.log] || n.log) + row("Port", PORT_LABEL[n.port] || n.port) +
            (art.classList.contains("is-sim") ? row("Traffic", n.traffic) : "") + "</dl>";
        } else if (filter) {
          var list = t.simpul.filter(function (n) { return matches(n, filter); });
          panel.innerHTML = closeBtn + '<p class="topo__name">' + FILTER_LABEL[filter] + " · " + list.length + " aset</p>" +
            "<p>" + esc(list.map(function (n) { return n.nama; }).join(", ") || "Tidak ada aset dengan status ini.") + "</p>";
        } else {
          panel.innerHTML = hint;
        }
        updateMeter();
        place();
      };

      // Pilih simpul: sorot jalurnya sampai akar (induk demi induk).
      var select = function (id) {
        selected = id;
        var path = id ? chain(id) : [];
        nodes.forEach(function (g) {
          var nid = g.getAttribute("data-id");
          g.classList.toggle("is-selected", nid === id);
          g.classList.toggle("is-chain", path.indexOf(nid) > 0);
          g.setAttribute("aria-pressed", String(nid === id));
        });
        edges.forEach(function (e) {
          var a = e.getAttribute("data-a"), b = e.getAttribute("data-b");
          var tree = byId[a].induk === b || byId[b].induk === a;
          e.classList.toggle("is-path", tree && path.indexOf(a) > -1 && path.indexOf(b) > -1);
          e.classList.toggle("is-child", !!id && ((a === id && byId[b].induk === id) || (b === id && byId[a].induk === id)));
        });
        art.classList.toggle("has-selection", !!id);
        renderPanel();
      };

      // Legenda berukir di sisi kiri: saring simpul menurut status log/port.
      var setFilter = function (f) {
        filter = f;
        art.classList.toggle("has-filter", !!f);
        legend.forEach(function (lg) { lg.setAttribute("aria-pressed", String(lg.getAttribute("data-filter") === f)); });
        nodes.forEach(function (g) { g.classList.toggle("is-match", !!f && matches(byId[g.getAttribute("data-id")], f)); });
        if (f && selected) { select(null); return; }
        renderPanel();
      };

      var tipFor = function (g) {
        if (!g) { tip.hidden = true; return; }
        var r = $(".topo__ring", g).getBoundingClientRect(), ar = art.getBoundingClientRect();
        tip.textContent = byId[g.getAttribute("data-id")].nama;
        tip.style.left = (r.left + r.width / 2 - ar.left).toFixed(0) + "px";
        tip.style.top = (r.top - ar.top).toFixed(0) + "px";
        tip.hidden = false;
      };
      var nodeOf = function (e) { return e.target.closest && e.target.closest(".topo__node"); };
      var legendOf = function (e) { return e.target.closest && e.target.closest(".topo__lg"); };
      var activate = function (e) {
        var g = nodeOf(e), lg = legendOf(e);
        if (g) { var id = g.getAttribute("data-id"); if (filter) setFilter(null); select(selected === id ? null : id); }
        else if (lg) { var f = lg.getAttribute("data-filter"); setFilter(filter === f ? null : f); }
      };

      svg.addEventListener("click", activate);
      svg.addEventListener("keydown", function (e) {
        if ((e.key === "Enter" || e.key === " ") && (nodeOf(e) || legendOf(e))) { e.preventDefault(); activate(e); }
      });
      art.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && (selected || filter)) { filter = null; setFilter(null); select(null); }
      });
      svg.addEventListener("pointerover", function (e) { tipFor(nodeOf(e)); });
      svg.addEventListener("pointerout", function (e) { if (!e.relatedTarget || !nodeOf({ target: e.relatedTarget })) tipFor(null); });
      svg.addEventListener("focusin", function (e) { tipFor(nodeOf(e)); });
      svg.addEventListener("focusout", function () { tipFor(null); });
      doc.addEventListener("layoutchange", place);
      panel.addEventListener("click", function (e) {
        if (!e.target.closest(".topo__close")) return;
        var back = selected ? $('.topo__node[data-id="' + selected + '"]', art) : filter ? $('.topo__lg[data-filter="' + filter + '"]', art) : null;
        filter = null; setFilter(null); select(null);
        if (back && back.focus) back.focus({ preventScroll: true }); // fokus kembali ke ukiran yang tadi dipilih
      });

      // Simulasi traffic: paket cahaya mengalir dari setiap aset ke induknya, sebanyak tingkat traffic-nya.
      var COUNT = { tinggi: 3, sedang: 2, rendah: 1 };
      var setSim = function (on) {
        art.classList.toggle("is-sim", on);
        btn.setAttribute("aria-pressed", String(on));
        btn.textContent = on ? "Hentikan simulasi" : "Simulasikan traffic";
        packets.innerHTML = "";
        renderPanel();
        if (!on || reduceMotion.matches) return; // gerak dikurangi: jalur aktif tetap disorot, tanpa paket bergerak
        t.simpul.forEach(function (n) {
          var p = n.induk && byId[n.induk], c = COUNT[n.traffic] || 0;
          if (!p || !c) return;
          var dur = Math.max(0.9, Math.hypot(p.x - n.x, p.y - n.y) / 150);
          for (var k = 0; k < c; k++) {
            var dot = doc.createElementNS(NS, "circle");
            dot.setAttribute("r", "7"); dot.setAttribute("class", "topo__packet");
            var am = doc.createElementNS(NS, "animateMotion");
            am.setAttribute("dur", dur.toFixed(2) + "s"); am.setAttribute("repeatCount", "indefinite");
            am.setAttribute("begin", (-(k / c) * dur).toFixed(2) + "s");
            am.setAttribute("path", "M" + n.x + " " + n.y + " L" + p.x + " " + p.y);
            dot.appendChild(am); packets.appendChild(dot);
          }
        });
      };
      btn.addEventListener("click", function () { setSim(btn.getAttribute("aria-pressed") !== "true"); });
      // Hemat daya: animasi berhenti saat kartu tidak terlihat.
      if ("IntersectionObserver" in window && svg.pauseAnimations) {
        new IntersectionObserver(function (en) { if (en[0].isIntersecting) svg.unpauseAnimations(); else svg.pauseAnimations(); }).observe(art);
      }
      select(null);
    });
  }

  /* ---------------- Pemecah teks untuk animasi ---------------- */
  // Bungkus setiap kata dalam <span class="w"><span class="w__i">, tanpa merusak <mark> di dalamnya.
  function splitWords(el) {
    if (!el || el.hasAttribute("data-split-done")) return;
    var i = 0;
    (function walk(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (n) {
        if (n.nodeType === 1) { walk(n); return; }
        if (n.nodeType !== 3) return;
        var frag = doc.createDocumentFragment();
        n.textContent.split(/(\s+)/).forEach(function (part) {
          if (!part) return;
          if (/^\s+$/.test(part)) { frag.appendChild(doc.createTextNode(part)); return; }
          var w = doc.createElement("span"); w.className = "w";
          var inner = doc.createElement("span"); inner.className = "w__i"; inner.textContent = part;
          inner.style.setProperty("--wi", i++);
          w.appendChild(inner); frag.appendChild(w);
        });
        n.parentNode.replaceChild(frag, n);
      });
    })(el);
    el.setAttribute("data-split-done", "");
  }

  // Nama di hero: tiap baris dipotong (overflow), tiap huruf naik bergiliran.
  function splitHeroName() {
    var h1 = $("[data-hero-name]");
    var name = h1.textContent.replace(/\s+/g, " ").trim();
    var ci = 0;
    h1.setAttribute("aria-label", name);
    // Salinan visual nama di panggung (aria-hidden), dipecah per huruf untuk animasi blur.
    var st = $("[data-stage-title]");
    if (st) {
      var si = 0, words = name.split(" "), first = words.shift();
      st.innerHTML = [first, words.join(" ")].filter(Boolean).map(function (line) {
        return "<span>" + Array.prototype.map.call(line, function (c) {
          return c === " " ? " " : '<span class="ch"><span class="ch__i" style="--ci:' + (si++) + '">' + esc(c) + "</span></span>";
        }).join("") + "</span>";
      }).join(" ");
    }
    Array.prototype.slice.call(h1.children).forEach(function (line) {
      line.className = "split-line";
      line.setAttribute("aria-hidden", "true");
      line.innerHTML = Array.prototype.map.call(line.textContent, function (c) {
        return c === " " ? " " : '<span class="ch"><span class="ch__i" style="--ci:' + (ci++) + '">' + esc(c) + "</span></span>";
      }).join("");
    });
  }

  function heroIntro() {
    if (reduceMotion.matches) return;
    var heroEl = $(".hero");
    heroEl.classList.add("is-ready");
    var go = function () {
      if (heroEl.classList.contains("is-intro")) return;
      // Dua frame supaya keadaan awal tergambar dulu sebelum transisi dimulai.
      requestAnimationFrame(function () { requestAnimationFrame(function () { heroEl.classList.add("is-intro"); }); });
      setTimeout(function () { heroEl.classList.add("is-intro-done"); }, 3200);
    };
    if (doc.fonts && doc.fonts.ready) doc.fonts.ready.then(go);
    setTimeout(go, 900); // jangan menunggu font terlalu lama
  }

  /* ---------------- 1. Render ---------------- */
  function render() {
    $$("[data-bind]").forEach(function (el) {
      var v = get(D, el.getAttribute("data-bind"));
      if (typeof v === "string" && v.trim()) el.innerHTML = rich(v);
    });

    var nama = (D.nama || "").trim();
    if (nama) {
      var parts = nama.split(/\s+/);
      var first = parts.shift();
      $("[data-hero-name]").innerHTML = "<span>" + esc(first) + "</span>" + (parts.length ? " <span>" + esc(parts.join(" ")) + "</span>" : "");
      doc.title = nama + " · Portofolio";
    }

    // Tentang
    var t = D.tentang || {};
    $("[data-about-body]").innerHTML = (t.paragraf || []).map(function (p) { return "<p>" + rich(p) + "</p>"; }).join("");
    $("[data-facts]").innerHTML = (t.fakta || []).map(function (f) {
      return "<div><dt>" + esc(f.label) + "</dt><dd>" + rich(f.nilai) + "</dd></div>";
    }).join("");
    if (t.foto && t.foto.trim()) {
      var art = $("[data-about-art]");
      art.removeAttribute("data-depth");
      art.outerHTML = '<img class="about__photo" src="' + esc(t.foto) + '" alt="Foto ' + esc(nama) + '">';
      // Koin dan fragmen kapitel hanya hiasan untuk patung; jangan menutupi foto asli.
      $$("[data-niche-decor]").forEach(function (d) { d.remove(); });
    }

    // Keahlian
    var skills = D.keahlian || [];
    // Benda di panel turun lalu mendarat di tempatnya saat kartu masuk layar (data-settle).
    $("[data-pillars]").innerHTML = skills.length ? skills.map(function (k, i) {
      var art = ICONS[k.ikon]
        ? '<div class="pillar__art" aria-hidden="true"><img class="pillar__img pillar__img--' + esc(k.ikon) + '" src="' + ICONS[k.ikon] +
          '" alt="" loading="lazy" decoding="async" data-depth="-0.07" data-settle></div>'
        : "";
      return '<li class="pillar" data-reveal style="--i:' + i + '">' + art + '<div class="pillar__body">' +
        "<h3>" + rich(k.judul) + "</h3>" +
        (k.moto ? '<p class="pillar__motto">' + rich(k.moto) + "</p>" : "") +
        "<ul>" + (k.daftar || []).map(function (d) { return "<li>" + rich(d) + "</li>"; }).join("") + "</ul>" +
        "</div></li>";
    }).join("") : '<li class="pillar"><div class="pillar__body"><p>Daftar keahlian belum diisi di js/data.js.</p></div></li>';

    // Karya
    var works = D.karya || [];
    var gh = ((D.kontak || {}).tautan || []).filter(function (l) { return /github/i.test(l.label) && hasUrl(l); })[0];
    var html = works.map(function (w, i) {
      var links = (w.tautan || []).filter(hasUrl);
      var art = w.gambar && w.gambar.trim()
        ? mediaHTML(w)
        : '<img class="work__amphora" src="assets/foto/amfora.webp" alt="" loading="lazy"><span class="work__caption">Tangkapan layar <mark class="ganti">[GANTI]</mark></span>';
      return '<li class="work' + (i === 0 ? " work--lead" : "") + (w.gambar && w.gambar.trim() ? " work--shot" : "") + '">' +
        '<div class="work__art' + (w.topologi ? ' topo" data-topo="' + i : "") + '"><span class="work__num" aria-hidden="true">' + (NUMERALS[i] || i + 1) + "</span>" + art + "</div>" +
        (w.topologi ? '<div class="topo__panel" data-topo-panel aria-live="polite"></div>' : "") +
        '<div class="work__body"><h3>' + rich(w.judul) + "</h3><p>" + rich(w.deskripsi) + "</p>" +
        ((w.teknologi || []).length ? '<ul class="tags" aria-label="Teknologi">' + w.teknologi.map(function (x) { return "<li>" + rich(x) + "</li>"; }).join("") + "</ul>" : "") +
        (links.length
          ? '<div class="work__links">' + links.map(function (l) { return '<a href="' + esc(l.url) + '" target="_blank" rel="noopener">' + esc(l.label) + newTab + "</a>"; }).join("") + "</div>"
          : '<span class="work__pending">Tautan menyusul</span>') +
        "</div></li>";
    }).join("");
    if (!works.length) {
      html = '<li class="work work--empty"><p>Karya sedang disiapkan.' + (gh ? ' Sementara itu, kode saya ada di <a href="' + esc(gh.url) + '" target="_blank" rel="noopener">GitHub' + newTab + "</a>." : "") + "</p></li>";
    }
    $("[data-works-track]").innerHTML = html;

    // Perjalanan
    var trip = D.perjalanan || [];
    $("[data-timeline]").innerHTML = trip.length ? trip.map(function (j) {
      return '<li data-reveal><span class="timeline__mark" aria-hidden="true"></span><div>' +
        '<p class="timeline__year">' + rich(j.tahun) + "</p><h3>" + rich(j.judul) + "</h3><p>" + rich(j.deskripsi) + "</p></div></li>";
    }).join("") : '<li class="timeline__empty">Lini masa belum diisi di js/data.js.</li>';

    // Kontak
    var k = D.kontak || {};
    var links = (k.tautan || []).filter(hasUrl);
    var email = (k.email || "").trim();
    var actions = "";
    if (email) {
      actions += '<a class="btn btn--gold" href="mailto:' + esc(email) + '">Tulis email</a>';
    } else if (links.length) {
      var main = links.shift();
      actions += '<a class="btn btn--gold" href="' + esc(main.url) + '" target="_blank" rel="noopener">Hubungi lewat ' + esc(main.label) + newTab + "</a>";
    }
    actions += links.map(function (l) {
      return '<a class="contact__link" href="' + esc(l.url) + '" target="_blank" rel="noopener">' + esc(l.label) + newTab + "</a>";
    }).join("");
    if (!email) actions += '<p class="contact__note"><mark class="ganti">[GANTI]</mark> Tambahkan alamat email di js/data.js agar tombol "Tulis email" muncul.</p>';
    $("[data-contact-actions]").innerHTML = actions;

    $$("[data-year]").forEach(function (el) { el.textContent = String(new Date().getFullYear()); });
  }

  /* ---------------- Bintang (posisi tetap, tanpa kedip berulang) ---------------- */
  function stars() {
    var box = $("[data-stars]");
    if (!box) return;
    var seed = 11;
    var rnd = function () { seed = (seed * 16807) % 2147483647; return seed / 2147483647; };
    var out = "";
    for (var i = 0; i < 90; i++) {
      var size = (1 + rnd() * 2.2).toFixed(1);
      out += '<span class="star" style="left:' + (rnd() * 100).toFixed(2) + "%;top:" + (rnd() * 100).toFixed(2) +
        "%;width:" + size + "px;height:" + size + "px;opacity:" + (0.3 + rnd() * 0.65).toFixed(2) + '"></span>';
    }
    box.innerHTML = out;
  }

  /* ---------------- 2–4. Gerak ---------------- */
  var hero = $(".hero");
  var stage = $("[data-stage]");
  var heroContent = $("[data-hero-content]");
  var layers = $$(".hero [data-speed]").map(function (el) {
    return { el: el, speed: parseFloat(el.getAttribute("data-speed")) || 0, mx: parseFloat(el.getAttribute("data-mx")) || 0 };
  });
  var titleLayer = $("[data-title-layer]");
  var nav = $("[data-nav]");
  var worksEl = $("[data-works]");
  var track = $("[data-works-track]");
  var hint = $("[data-works-hint]");

  var state = { scale: 1, heroH: 0, worksTop: 0, dist: 0, pinned: false, px: 0, py: 0, tx: 0, ty: 0, ticking: false };

  function measure() {
    var w = window.innerWidth;
    state.heroH = hero.offsetHeight;
    state.scale = Math.max(w / 1440, state.heroH / 900);
    stage.style.setProperty("--s", state.scale.toFixed(4));
    // Tepi kiri dan kanan yang terlihat, dalam satuan panggung: lapisan bisa ditambatkan ke tepi layar
    // berapa pun rasio layarnya (dipakai tiang dan patung di layar tegak).
    stage.style.setProperty("--vis-l", (720 - w / state.scale / 2).toFixed(1) + "px");
    stage.style.setProperty("--vis-r", (720 + w / state.scale / 2).toFixed(1) + "px");
    // Nama raksasa selalu di bawah sapaan. Di layar lebar tapi pendek, bagian atas panggung terpotong,
    // jadi nama diturunkan secukupnya (dan sedikit dikecilkan) agar tidak menimpa sapaan.
    // Cakrawala (awan, istana, merpati) ikut turun sejauh bagian atas yang terpotong, jadi jarak sapaan, nama,
    // dan atap istana tetap sama seperti di layar 1440 × 900.
    var visTop = 900 - state.heroH / state.scale;
    stage.style.setProperty("--hz-auto", visTop.toFixed(1) + "px");
    var greet = $(".hero__top .overline");
    if (titleLayer && greet) {
      var need = visTop + (greet.offsetTop + greet.offsetHeight + 24) / state.scale;
      var titleTop = Math.max(168 + visTop, need);
      stage.style.setProperty("--title-top", titleTop.toFixed(1) + "px");
      stage.style.setProperty("--title-size", Math.max(100, 140 - (titleTop - 168 - visTop) * 0.5).toFixed(1) + "px");
    }

    state.pinned = wide.matches && !reduceMotion.matches && track.children.length > 1;
    worksEl.classList.toggle("is-pinned", state.pinned);
    track.style.transform = "";
    if (state.pinned) {
      var last = track.lastElementChild;
      var gutter = parseFloat(getComputedStyle(track).paddingLeft) || 0;
      state.dist = Math.max(0, last.getBoundingClientRect().right - track.getBoundingClientRect().left + gutter - track.clientWidth);
      worksEl.style.height = window.innerHeight + state.dist + "px";
    } else {
      state.dist = 0;
      worksEl.style.height = "";
    }
    if (hint) hint.hidden = !state.pinned || state.dist === 0;
    state.worksTop = worksEl.getBoundingClientRect().top + window.scrollY;
    state.docH = doc.documentElement.scrollHeight;
    fx.amphorae = $$(".work__amphora, .work__media");
    // Elemen berkedalaman dicatat sekali di sini, bukan dicari ulang setiap frame.
    // rest = titik di layar tempat elemen berada tepat di posisi CSS-nya (biasanya tengah layar).
    // Saat galeri dipin, header karya diam di atas layar, jadi titik diamnya adalah posisi pin itu.
    var stickyBox = $("[data-works-sticky]").getBoundingClientRect();
    fx.depth = $$("[data-depth]").map(function (el) {
      var inWorks = !!el.closest("[data-works]"), pr = el.parentElement.getBoundingClientRect();
      return {
        el: el,
        d: parseFloat(el.getAttribute("data-depth")) || 0,
        pan: parseFloat(el.getAttribute("data-pan-x")) || 0,
        settle: el.hasAttribute("data-settle"),
        inWorks: inWorks,
        rest: inWorks && state.pinned ? pr.top - stickyBox.top + pr.height / 2 : null
      };
    });
    doc.dispatchEvent(new CustomEvent("layoutchange")); // mis. demo topologi menata ulang panelnya
  }

  // Elemen efek gulir tambahan (diisi saat initMotion).
  var fx = { bar: null, frieze: null, rows: [], journey: null, birds: null, amphorae: [], depth: [] };
  var progressIn = function (r, vh) { return Math.min(1, Math.max(0, (vh - r.top) / (vh + r.height))); };

  function frame() {
    state.ticking = false;
    var y = window.scrollY;
    var vh = window.innerHeight;
    var motion = !reduceMotion.matches;

    // Hero: lapisan jauh tertinggal, lapisan dekat menyusul lebih cepat.
    if (motion && y < state.heroH + 50) {
      state.px += (state.tx - state.px) * 0.08;
      state.py += (state.ty - state.py) * 0.08;
      for (var i = 0; i < layers.length; i++) {
        var L = layers[i];
        // Hero sticky: setiap lapisan naik sebanding kecepatannya sendiri.
        var ty = -(y * L.speed) / state.scale;
        var mx = -state.px * L.mx;
        var my = -state.py * L.mx * 0.35;
        L.el.style.transform = "translate3d(" + mx.toFixed(2) + "px," + (ty + my).toFixed(2) + "px,0)";
      }
      var p = Math.min(1, y / (state.heroH * 0.75));
      heroContent.style.transform = "translate3d(0," + (-y * 0.45).toFixed(1) + "px,0)";
      heroContent.style.opacity = String(1 - p);
      // Zoom pelan ke arah Akropolis saat hero digulir.
      stage.style.setProperty("--zoom", (1 + Math.min(1, y / state.heroH) * 0.1).toFixed(4));
      // Nama raksasa makin kabur dan pudar saat tirai bagian berikutnya naik.
      if (titleLayer) {
        var tp = Math.min(1, y / (state.heroH * 0.7));
        titleLayer.style.filter = tp > 0.01 ? "blur(" + (tp * 10).toFixed(1) + "px)" : "";
        titleLayer.style.opacity = String(Math.max(0, 1 - tp * 1.1));
      }
      if (Math.abs(state.tx - state.px) > 0.001 || Math.abs(state.ty - state.py) > 0.001) request();
    }

    var galleryOn = state.pinned && state.dist > 0;
    var prog = galleryOn ? Math.min(1, Math.max(0, (y - state.worksTop) / state.dist)) : 0;

    // Elemen dengan kedalaman di luar hero, diukur terhadap induknya:
    // depth positif tertinggal (terasa jauh), negatif mendahului (terasa dekat).
    // data-settle: hanya naik ke posisinya lalu diam, jadi benda yang "berdiri" tidak melayang.
    // data-pan-x: ikut bergeser ke samping sepanjang induknya melintasi layar (atau sepanjang galeri saat dipin).
    if (motion) {
      fx.depth.forEach(function (D) {
        var r = D.el.parentElement.getBoundingClientRect();
        if (r.bottom < -200 || r.top > vh + 200) return;
        var off = -((r.top + r.height / 2) - (D.rest === null ? vh / 2 : D.rest)) * D.d;
        if (D.settle) off = Math.max(0, off);
        var x = 0;
        if (D.pan) x = ((D.inWorks && galleryOn ? prog : progressIn(r, vh)) - 0.5) * D.pan;
        D.el.style.transform = "translate3d(" + x.toFixed(1) + "px," + off.toFixed(1) + "px,0)";
      });
    }

    // Galeri: gulir vertikal diterjemahkan menjadi geser horizontal.
    if (galleryOn) {
      track.style.transform = "translate3d(" + (-prog * state.dist).toFixed(1) + "px,0,0)";
    }

    // Garis kemajuan halaman (tetap aktif walau gerak dikurangi, karena ini penanda posisi).
    if (fx.bar) fx.bar.style.transform = "scaleX(" + Math.min(1, y / Math.max(1, state.docH - vh)).toFixed(4) + ")";

    if (motion) {
      var vw = window.innerWidth;
      // Frieze prasasti: dua baris bergeser berlawanan arah.
      if (fx.frieze) {
        var fr = fx.frieze.getBoundingClientRect();
        if (fr.bottom > 0 && fr.top < vh) {
          var fp = progressIn(fr, vh);
          fx.rows.forEach(function (row) {
            row.style.transform = "translate3d(" + ((fp - 0.5) * 720 * row.dir).toFixed(1) + "px,0,0)";
          });
        }
      }
      // Burung camar melintasi bagian Perjalanan.
      if (fx.birds) {
        var jr = fx.journey.getBoundingClientRect();
        if (jr.bottom > 0 && jr.top < vh) {
          var jp = progressIn(jr, vh);
          var bx = -340 + jp * (jr.width + 680);
          var by = -Math.sin(jp * Math.PI) * 46;
          fx.birds.style.transform = "translate3d(" + bx.toFixed(1) + "px," + by.toFixed(1) + "px,0)";
        }
      }
      // Amfora bergeser lebih lambat daripada panelnya (parallax di dalam bingkai).
      fx.amphorae.forEach(function (a) {
        var r = a.parentElement.getBoundingClientRect();
        if (r.right < -100 || r.left > vw + 100 || r.bottom < -100 || r.top > vh + 100) return;
        // Gambar karya (bukan amfora) digeser lebih halus dan dibatasi agar tepinya tidak terlihat.
        var shot = a.classList.contains("work__media");
        var lim = shot ? (a.parentElement.classList.contains("topo") ? 10 : 20) : 1e4;
        var clamp = function (v) { return Math.max(-lim, Math.min(lim, v)); };
        var ox = state.pinned ? clamp(-((r.left + r.width / 2) - vw / 2) * (shot ? 0.07 : 0.14)) : 0;
        var oy = state.pinned ? 0 : clamp(-((r.top + r.height / 2) - vh / 2) * (shot ? 0.06 : 0.1));
        a.style.translate = ox.toFixed(1) + "px " + oy.toFixed(1) + "px";
      });
    }

    nav.classList.toggle("is-solid", y > state.heroH - 90);
  }

  function request() {
    if (!state.ticking) { state.ticking = true; window.requestAnimationFrame(frame); }
  }

  function resetMotion() {
    layers.forEach(function (L) { L.el.style.transform = ""; });
    fx.depth.forEach(function (D) { D.el.style.transform = ""; });
    heroContent.style.transform = "";
    heroContent.style.opacity = "";
    stage.style.removeProperty("--zoom");
    if (titleLayer) { titleLayer.style.filter = ""; titleLayer.style.opacity = ""; }
    fx.rows.forEach(function (r) { r.style.transform = ""; });
    if (fx.birds) fx.birds.style.transform = "";
    fx.amphorae.forEach(function (a) { a.style.translate = ""; });
  }

  function initMotion() {
    fx.bar = $("[data-progress]");
    fx.frieze = $("[data-frieze]");
    fx.rows = $$("[data-frieze] .frieze__row").map(function (r) { r.dir = parseFloat(r.getAttribute("data-frieze-dir")) || 1; return r; });
    fx.journey = $(".journey");
    fx.birds = $("[data-drift]");
    measure();
    window.addEventListener("scroll", request, { passive: true });
    window.addEventListener("resize", function () { measure(); request(); });
    window.addEventListener("load", function () { measure(); request(); });
    var onPrefChange = function () { if (reduceMotion.matches) resetMotion(); measure(); request(); };
    if (reduceMotion.addEventListener) reduceMotion.addEventListener("change", onPrefChange);

    hero.addEventListener("pointermove", function (e) {
      if (!finePointer.matches || reduceMotion.matches) return;
      state.tx = e.clientX / window.innerWidth - 0.5;
      state.ty = e.clientY / window.innerHeight - 0.5;
      request();
    });
    hero.addEventListener("pointerleave", function () { state.tx = 0; state.ty = 0; request(); });

    // Saat kartu galeri difokus dengan keyboard, gulir sampai kartu itu terlihat.
    track.addEventListener("focusin", function (e) {
      if (!state.pinned || !state.dist) return;
      var card = e.target.closest(".work");
      if (!card) return;
      var prev = track.style.transform;
      track.style.transform = "";
      var gutter = parseFloat(getComputedStyle(track).paddingLeft) || 0;
      var x = card.getBoundingClientRect().left - track.getBoundingClientRect().left - gutter;
      track.style.transform = prev;
      var prog = Math.min(1, Math.max(0, x / state.dist));
      window.scrollTo({ top: state.worksTop + prog * state.dist, behavior: "instant" });
    });
    request();
  }

  function initNav() {
    var toggle = $(".nav__toggle");
    var label = $(".nav__toggle-label");
    var setOpen = function (open) {
      nav.classList.toggle("is-open", open);
      toggle.setAttribute("aria-expanded", String(open));
      label.textContent = open ? "Tutup" : "Menu";
    };
    toggle.addEventListener("click", function () { setOpen(toggle.getAttribute("aria-expanded") !== "true"); });
    doc.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && nav.classList.contains("is-open")) { setOpen(false); toggle.focus(); }
    });
    $$(".nav__menu a").forEach(function (a) { a.addEventListener("click", function () { setOpen(false); }); });

    if (!("IntersectionObserver" in window)) return;
    var links = {};
    $$(".nav__menu a").forEach(function (a) { links[a.getAttribute("href").slice(1)] = a; });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        var a = links[en.target.id];
        if (!a) return;
        if (en.isIntersecting) {
          Object.keys(links).forEach(function (k) { links[k].removeAttribute("aria-current"); });
          a.setAttribute("aria-current", "true");
        } else if (a.getAttribute("aria-current")) {
          a.removeAttribute("aria-current");
        }
      });
    }, { rootMargin: "-45% 0px -50% 0px" });
    $$("main section[id]").forEach(function (s) { io.observe(s); });
  }

  function initReveal() {
    var items = $$("[data-reveal], [data-split], [data-carve]");
    if (!("IntersectionObserver" in window) || reduceMotion.matches) {
      items.forEach(function (el) { el.classList.add("is-visible"); });
      return;
    }
    // Elemen bertirai (clip-path 100%) dianggap berukuran nol oleh observer,
    // jadi yang diamati adalah induknya, lalu kelasnya dipasang ke elemen itu.
    var targets = new Map();
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        (targets.get(en.target) || [en.target]).forEach(function (el) { el.classList.add("is-visible"); });
        io.unobserve(en.target);
      });
    }, { threshold: 0.15, rootMargin: "0px 0px -40px 0px" });
    items.forEach(function (el) {
      var watch = el.getAttribute("data-reveal") === "curtain" ? el.parentElement : el;
      if (watch !== el) targets.set(watch, (targets.get(watch) || []).concat(el));
      io.observe(watch);
    });
  }

  /* ---------------- Mulai ---------------- */
  if (!D) {
    // Status galat: data.js gagal dimuat atau rusak.
    var err = $("[data-load-error]");
    if (err) err.hidden = false;
    nav.classList.add("is-solid");
    doc.documentElement.classList.remove("js");
    initNav();
    return;
  }
  render();
  splitHeroName();
  splitWords($("[data-split-hero]"));
  $$("[data-split]").forEach(splitWords);
  stars();
  initNav();
  initReveal();
  initTopo();
  initMotion();
  heroIntro();
})();
