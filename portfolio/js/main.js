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
  var ICONS = {
    "helm": "assets/foto/ikon-helm.webp",
    "burung-hantu": "assets/foto/ikon-burung-hantu.webp",
    "lira": "assets/foto/ikon-lira.webp",
    "gulungan": "assets/foto/ikon-gulungan.webp"
  };
  var newTab = '<span class="visually-hidden"> (tab baru)</span>';
  var ICON_DEPTH = [0.05, -0.04, 0.07, -0.05]; // ikon pilar melayang dengan kedalaman berbeda

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
    }

    // Keahlian
    var skills = D.keahlian || [];
    $("[data-pillars]").innerHTML = skills.length ? skills.map(function (k, i) {
      var icon = ICONS[k.ikon] ? '<img class="pillar__icon" src="' + ICONS[k.ikon] + '" alt="" width="56" height="56" data-depth="' + ICON_DEPTH[i % 4] + '">' : "";
      return '<li class="pillar" data-reveal style="--i:' + i + '"><div class="pillar__shaft">' + icon +
        "<h3>" + rich(k.judul) + "</h3>" +
        (k.moto ? '<p class="pillar__motto">' + rich(k.moto) + "</p>" : "") +
        "<ul>" + (k.daftar || []).map(function (d) { return "<li>" + rich(d) + "</li>"; }).join("") + "</ul>" +
        "</div></li>";
    }).join("") : '<li class="pillar"><div class="pillar__shaft"><p>Daftar keahlian belum diisi di js/data.js.</p></div></li>';

    // Karya
    var works = D.karya || [];
    var gh = ((D.kontak || {}).tautan || []).filter(function (l) { return /github/i.test(l.label) && hasUrl(l); })[0];
    var html = works.map(function (w, i) {
      var links = (w.tautan || []).filter(hasUrl);
      var art = w.gambar && w.gambar.trim()
        ? '<img class="work__shot" src="' + esc(w.gambar) + '" alt="Tangkapan layar ' + esc(w.judul) + '" loading="lazy">'
        : '<img class="work__amphora" src="assets/foto/amfora.webp" alt="" loading="lazy"><span class="work__caption">Tangkapan layar <mark class="ganti">[GANTI]</mark></span>';
      return '<li class="work' + (i === 0 ? " work--lead" : "") + '">' +
        '<div class="work__art"><span class="work__num" aria-hidden="true">' + (NUMERALS[i] || i + 1) + "</span>" + art + "</div>" +
        '<div class="work__body"><h3>' + rich(w.judul) + "</h3><p>" + rich(w.deskripsi) + "</p>" +
        ((w.teknologi || []).length ? '<ul class="tags" aria-label="Teknologi">' + w.teknologi.map(function (x) { return "<li>" + rich(x) + "</li>"; }).join("") + "</ul>" : "") +
        (links.length
          ? '<div class="work__links">' + links.map(function (l) { return '<a href="' + esc(l.url) + '" target="_blank" rel="noopener">' + esc(l.label) + newTab + "</a>"; }).join("") + "</div>"
          : '<span class="work__pending">Tautan menyusul</span>') +
        "</div></li>";
    }).join("");
    if (!works.length) {
      html = '<li class="work work--empty"><p>Karya sedang disiapkan.' + (gh ? ' Sementara itu, kode saya ada di <a href="' + esc(gh.url) + '" target="_blank" rel="noopener">GitHub' + newTab + "</a>." : "") + "</p></li>";
    } else if (gh) {
      html += '<li class="work work--more"><h3>Lebih banyak kode</h3><p>Repositori lain dan riwayat kontribusi ada di GitHub.</p>' +
        '<a href="' + esc(gh.url) + '" target="_blank" rel="noopener">Buka profil GitHub' + newTab + "</a></li>";
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
    fx.amphorae = $$(".work__amphora");
  }

  // Elemen efek gulir tambahan (diisi saat initMotion).
  var fx = { bar: null, bands: [], frieze: null, rows: [], journey: null, birds: null, amphorae: [] };
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

    // Elemen dengan kedalaman di luar hero.
    if (motion) {
      $$("[data-depth]").forEach(function (el) {
        var r = el.parentElement.getBoundingClientRect();
        if (r.bottom < -200 || r.top > vh + 200) return;
        var off = -((r.top + r.height / 2) - vh / 2) * parseFloat(el.getAttribute("data-depth"));
        if (el.hasAttribute("data-about-art")) off = Math.max(0, off);
        el.style.transform = "translate3d(0," + off.toFixed(1) + "px,0)";
      });
    }

    // Galeri: gulir vertikal diterjemahkan menjadi geser horizontal.
    if (state.pinned && state.dist > 0) {
      var prog = Math.min(1, Math.max(0, (y - state.worksTop) / state.dist));
      track.style.transform = "translate3d(" + (-prog * state.dist).toFixed(1) + "px,0,0)";
    }

    // Garis kemajuan halaman (tetap aktif walau gerak dikurangi, karena ini penanda posisi).
    if (fx.bar) fx.bar.style.transform = "scaleX(" + Math.min(1, y / Math.max(1, state.docH - vh)).toFixed(4) + ")";

    if (motion) {
      var vw = window.innerWidth;
      // Pita meander berjalan ke samping, arah bergantian.
      fx.bands.forEach(function (b, i) {
        var r = b.getBoundingClientRect();
        if (r.bottom < 0 || r.top > vh) return;
        b.style.setProperty("--band-x", ((i % 2 ? 1 : -1) * y * 0.25 % 24).toFixed(1) + "px");
      });
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
        var ox = state.pinned ? -((r.left + r.width / 2) - vw / 2) * 0.14 : 0;
        var oy = state.pinned ? 0 : -((r.top + r.height / 2) - vh / 2) * 0.1;
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
    $$("[data-depth]").forEach(function (el) { el.style.transform = ""; });
    heroContent.style.transform = "";
    heroContent.style.opacity = "";
    stage.style.removeProperty("--zoom");
    if (titleLayer) { titleLayer.style.filter = ""; titleLayer.style.opacity = ""; }
    fx.bands.forEach(function (b) { b.style.removeProperty("--band-x"); });
    fx.rows.forEach(function (r) { r.style.transform = ""; });
    if (fx.birds) fx.birds.style.transform = "";
    fx.amphorae.forEach(function (a) { a.style.translate = ""; });
  }

  function initMotion() {
    fx.bar = $("[data-progress]");
    fx.bands = $$(".band, .temple__architrave");
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
  initMotion();
  heroIntro();
})();
