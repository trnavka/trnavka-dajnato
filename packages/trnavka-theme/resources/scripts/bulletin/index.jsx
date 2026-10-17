import React, { useState, useEffect, useRef } from 'react'
import { createRoot } from 'react-dom/client'
import { createPortal } from 'react-dom'

// Editor for the weekly parish bulletin ("farské oznamy"). State is serialized
// into hidden inputs that the standard WP form submit saves.

const WEEKDAYS = ['Nedeľa', 'Pondelok', 'Utorok', 'Streda', 'Štvrtok', 'Piatok', 'Sobota'] // Date.getDay() 0..6
const CORE_TYPES = ['slávnosť', 'sviatok', 'spomienka'] // checked by default after a KBS load
const TYPE_ORDER = ['slávnosť', 'sviatok', 'spomienka', 'ľubovoľná spomienka', 'variant', 'poznámka']
const typesIn = (days) => TYPE_ORDER.filter((t) => days.some((d) => d.options.some((o) => o.type === t)))

let counter = 0
const uid = () => `b${++counter}_${Math.random().toString(36).slice(2, 8)}`

const newItem = () => ({ id: uid(), label: '', text: '' })
const asItem = (i) => ({ id: uid(), label: i.label || '', text: i.text || '' })
const asLiturgy = (r) => ({
    id: uid(),
    day: r.day ? String(r.day) : '',
    month: r.month ? String(r.month) : '',
    text: r.text || '',
    type: r.type || 'manual', // KBS celebration type, or 'manual' for hand-added/edited rows
    kbsKey: r.kbsKey ?? null, // identity of the source KBS option (null when manual)
    pristine: r.pristine ?? false, // true only while untouched after a KBS load — only these are removed by type toggles
})

const titleInput = () => document.getElementById('title')

// Host node inserted directly above the WP title field (#titlediv) so the date
// picker and the "Načítať z KBS" control can be portaled in above post_title.
const titleHeaderHost = () => {
    const titlediv = document.getElementById('titlediv')
    if (!titlediv || !titlediv.parentNode) return null

    let host = document.getElementById('bulletin-title-header')
    if (!host) {
        host = document.createElement('div')
        host.id = 'bulletin-title-header'
        titlediv.parentNode.insertBefore(host, titlediv)
    }
    return host
}

// Host node inside the Publish box (#misc-publishing-actions) so the "view on
// web" / "download PDF" links can be portaled into the right-hand sidebar.
const publishActionsHost = () => {
    const misc = document.getElementById('misc-publishing-actions')
    if (!misc) return null

    let host = document.getElementById('bulletin-publish-actions')
    if (!host) {
        host = document.createElement('div')
        host.id = 'bulletin-publish-actions'
        host.className = 'misc-pub-section'
        misc.appendChild(host)
    }
    return host
}

const nextSunday = () => {
    const d = new Date()
    d.setDate(d.getDate() + ((7 - d.getDay()) % 7)) // 0 = Sunday
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// Weekday name (and Sunday check) of a full Y-M-D date — drives the hint on the
// date field, since the bulletin date is normally a Sunday.
const weekdayName = (ymd) => {
    const [y, m, d] = (ymd || '').split('-').map(Number)
    return y && m && d ? WEEKDAYS[new Date(y, m - 1, d).getDay()] : ''
}
const isSundayYmd = (ymd) => {
    const [y, m, d] = (ymd || '').split('-').map(Number)
    return !!(y && m && d) && new Date(y, m - 1, d).getDay() === 0
}

const clampInt = (value, max) => {
    const n = parseInt(value, 10)
    return value === '' || isNaN(n) ? '' : String(Math.min(max, Math.max(1, n)))
}

// Effective Date for day+month in the bulletin's week (handles the year boundary).
const effectiveDate = (day, month, sundayYmd) => {
    const dd = parseInt(day, 10)
    const mm = parseInt(month, 10)
    if (!dd || !mm || !sundayYmd) return null
    const baseYear = parseInt(sundayYmd.slice(0, 4), 10)
    const baseMonth = parseInt(sundayYmd.slice(5, 7), 10)
    const year = baseMonth && mm < baseMonth ? baseYear + 1 : baseYear
    const d = new Date(year, mm - 1, dd)
    return d.getFullYear() === year && d.getMonth() === mm - 1 && d.getDate() === dd ? d : null // null = invalid date
}

const weekdayOf = (day, month, sundayYmd) => {
    const d = effectiveDate(day, month, sundayYmd)
    return d ? WEEKDAYS[d.getDay()] : ''
}

const byDate = (sundayYmd) => (a, b) => {
    const da = effectiveDate(a.day, a.month, sundayYmd)
    const db = effectiveDate(b.day, b.month, sundayYmd)
    return (da ? da.getTime() : Infinity) - (db ? db.getTime() : Infinity)
}

const move = (arr, index, dir) => {
    const next = index + dir
    if (next < 0 || next >= arr.length) return arr
    const copy = arr.slice()
    copy.splice(next, 0, copy.splice(index, 1)[0])
    return copy
}

const escapeHtml = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

// Bare web links in plain text — full http(s) URLs and bare "www." hosts. The
// char class runs until whitespace/quotes/angle brackets; trailing sentence
// punctuation is peeled off in linkify() so it stays outside the link.
const URL_RE = /\b(?:https?:\/\/|www\.)[^\s<>"']+/gi
const hasUrl = (s) => /\b(?:https?:\/\/|www\.)\S/i.test(s)

// Wrap every bare URL in raw text with an <a> tag and HTML-escape the rest, so a
// pasted or typed link becomes clickable. A trailing ".,;:!?" (and a ")" with no
// matching "(") stays outside the link; "www." links get an https:// href.
const linkify = (text) => {
    URL_RE.lastIndex = 0
    let out = '', last = 0, m
    while ((m = URL_RE.exec(text)) !== null) {
        let url = m[0], trail = '', ch
        while ((ch = url.slice(-1)) && (/[.,;:!?]/.test(ch) || (ch === ')' && !url.slice(0, -1).includes('(')))) {
            trail = ch + trail
            url = url.slice(0, -1)
        }
        const href = /^www\./i.test(url) ? `https://${url}` : url
        out += escapeHtml(text.slice(last, m.index)) + `<a href="${escapeHtml(href)}">${escapeHtml(url)}</a>` + escapeHtml(trail)
        last = m.index + m[0].length
    }
    return out + escapeHtml(text.slice(last))
}

// Turn bare URLs inside an element's text nodes into real <a> links, in place,
// leaving existing links and formatting untouched. Returns true if it changed
// anything. Used on blur so typed/edited links also become clickable.
const linkifyElement = (el) => {
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT)
    const targets = []
    let node
    while ((node = walker.nextNode())) {
        if (node.parentElement && !node.parentElement.closest('a') && hasUrl(node.nodeValue)) targets.push(node)
    }
    targets.forEach((textNode) => {
        const tpl = document.createElement('template')
        tpl.innerHTML = linkify(textNode.nodeValue)
        textNode.replaceWith(tpl.content)
    })
    return targets.length > 0
}

// Every http(s) link in an announcement's HTML, in document order (with repeats).
// The QR list is built per occurrence so its badge numbers line up 1:1 with the
// CSS counter that numbers the <a> tags inside the editable text.
const extractLinkList = (html) => {
    const div = document.createElement('div')
    div.innerHTML = html || ''
    const urls = []
    div.querySelectorAll('a[href]').forEach((a) => {
        const href = a.getAttribute('href')
        if (/^https?:\/\//i.test(href)) urls.push(href)
    })
    return urls
}

// Unique links — what actually gets shortened/fetched.
const extractLinks = (html) => [...new Set(extractLinkList(html))]

const BLOCK_TAGS = new Set(['P', 'DIV', 'LI', 'TR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'UL', 'OL', 'BLOCKQUOTE', 'TABLE'])
// dropped whole (Word pastes a <style> block with CSS/@font-face definitions)
const SKIP_TAGS = new Set(['STYLE', 'SCRIPT', 'HEAD', 'META', 'TITLE', 'LINK', 'NOSCRIPT', 'OBJECT', 'IFRAME'])

// Clean pasted HTML into an array of paragraphs — one block element = one
// paragraph — keeping inline text, links and intra-paragraph line breaks. Bold/
// colours are dropped (these Word docs are bold throughout; bold is applied
// manually with the B button). A multi-paragraph paste thus yields several entries.
const cleanPasteParagraphs = (html) => {
    const root = document.createElement('div')
    root.innerHTML = html
    const paragraphs = []
    let current = ''

    const flush = () => {
        const text = current
            .replace(/(\s*<br>\s*){2,}/g, '<br>')
            .replace(/^(?:\s*<br>\s*)+|(?:\s*<br>\s*)+$/g, '')
            .trim()
        if (text) paragraphs.push(text)
        current = ''
    }

    const walk = (node, href) => {
        node.childNodes.forEach((child) => {
            if (child.nodeType === 3) {
                const raw = child.nodeValue.replace(/\s+/g, ' ')
                // inside an existing link: keep as link text; otherwise auto-link bare URLs
                if (raw) current += href ? `<a href="${escapeHtml(href)}">${escapeHtml(raw)}</a>` : linkify(raw)
            } else if (child.nodeType === 1) {
                const tag = child.tagName
                if (SKIP_TAGS.has(tag)) return
                if (tag === 'BR') { current += '<br>'; return }
                const h = tag === 'A' && child.getAttribute('href') ? child.getAttribute('href') : href
                if (BLOCK_TAGS.has(tag)) { flush(); walk(child, h); flush() } // block = paragraph boundary
                else walk(child, h)
            }
        })
    }

    walk(root, null)
    flush()
    return paragraphs
}

// Plain-text fallback: blank lines separate paragraphs, single newlines become <br>.
const plainTextParagraphs = (text) => (text || '')
    .split(/\n[ \t]*\n+/)
    .map((p) => linkify(p.trim()).replace(/\n/g, '<br>'))
    .filter(Boolean)

const pasteParagraphs = (clipboardData) => {
    const html = clipboardData.getData('text/html')
    return html ? cleanPasteParagraphs(html) : plainTextParagraphs(clipboardData.getData('text/plain'))
}

// Uncontrolled contentEditable with a tiny toolbar (bold + link) shown on focus.
// onSplit (optional) receives the extra paragraphs of a multi-paragraph paste.
function RichText({ html, onChange, placeholder, onSplit }) {
    const ref = useRef(null)
    const [focused, setFocused] = useState(false)

    useEffect(() => {
        // Sync from prop only when it differs and the user is not typing (avoids caret jumps).
        if (ref.current && document.activeElement !== ref.current && ref.current.innerHTML !== (html || '')) {
            ref.current.innerHTML = html || ''
        }
    }, [html])

    const fire = () => ref.current && onChange(ref.current.innerHTML)
    const exec = (cmd, val = null) => { document.execCommand(cmd, false, val); fire() }
    const addLink = () => { const url = window.prompt('Adresa odkazu (URL):', 'https://'); if (url) exec('createLink', url) }

    // On blur, auto-link any bare URL that was typed/edited (paste is handled in onPaste).
    const onBlur = () => {
        setFocused(false)
        if (ref.current && linkifyElement(ref.current)) fire()
    }

    const onPaste = (e) => {
        e.preventDefault()
        const cd = e.clipboardData
        if (!cd) return
        const paragraphs = pasteParagraphs(cd)
        if (!paragraphs.length) return

        // first paragraph into this field; the rest become new announcements below
        document.execCommand('insertHTML', false, paragraphs[0])
        fire()
        if (paragraphs.length > 1 && onSplit) onSplit(paragraphs.slice(1))
    }

    return (
        <div className={`bulletin-rt${focused ? ' is-focused' : ''}`}>
            <div className="bulletin-rt-toolbar">
                <button type="button" title="Tučné" onMouseDown={(e) => { e.preventDefault(); exec('bold') }}><b>B</b></button>
                <button type="button" title="Vložiť odkaz" onMouseDown={(e) => { e.preventDefault(); addLink() }}><span className="dashicons dashicons-admin-links" /></button>
                <button type="button" title="Zrušiť odkaz" onMouseDown={(e) => { e.preventDefault(); exec('unlink') }}><span className="dashicons dashicons-editor-unlink" /></button>
            </div>
            <div ref={ref} className="bulletin-rt-editable" contentEditable data-placeholder={placeholder || ''}
                onInput={fire} onPaste={onPaste}
                onFocus={() => setFocused(true)} onBlur={onBlur} />
        </div>
    )
}

// QR for one link in an announcement (shown below the text). The badge number
// matches the one on the link in the text (CSS counter, see styles). Hovering a
// QR sets it active (→ outline + its link highlights); `active` also reflects a
// hover on the link itself.
function LinkQr({ number, url, state, onHover, active }) {
    return (
        <div className={`bulletin-link${active ? ' is-qr-active' : ''}`}
            onMouseEnter={() => onHover(true)} onMouseLeave={() => onHover(false)}>
            <span className="bulletin-link-badge">{number}</span>
            {state?.status === 'ok' ? (
                <img className="bulletin-qr" src={state.qr} alt={`QR ${number}`} width="84" height="84" />
            ) : state?.status === 'error' ? (
                <span className="bulletin-link-error" title={url}>QR sa nepodarilo vytvoriť</span>
            ) : (
                <span className="bulletin-link-loading">vytváram QR…</span>
            )}
        </div>
    )
}

function ItemRow({ item, index, total, onChange, onRemove, onMove, onSplit, shortLinks, onSuggestTitle, canSuggest }) {
    const links = extractLinkList(item.text) // per occurrence, so badges line up with the in-text numbers
    const [suggesting, setSuggesting] = useState(false)
    const [activeLink, setActiveLink] = useState(null) // shared QR↔link highlight (index, or null)
    const rowRef = useRef(null)
    const hasText = !!item.text.replace(/<[^>]*>/g, '').trim()

    const suggest = async () => {
        setSuggesting(true)
        const title = await onSuggestTitle(item.text)
        if (title) onChange(item.id, { label: title })
        setSuggesting(false)
    }

    const editableLinks = () => {
        const editable = rowRef.current?.querySelector('.bulletin-rt-editable')
        return editable
            ? [...editable.querySelectorAll('a[href]')].filter((a) => /^https?:\/\//i.test(a.getAttribute('href') || ''))
            : []
    }

    // Hovering a link in the text sets it active (→ highlights its QR).
    useEffect(() => {
        const editable = rowRef.current?.querySelector('.bulletin-rt-editable')
        if (!editable) return
        const over = (e) => {
            const a = e.target.closest?.('a[href]')
            const ok = a && editable.contains(a) && /^https?:\/\//i.test(a.getAttribute('href') || '')
            setActiveLink(ok ? editableLinks().indexOf(a) : null)
        }
        const leave = () => setActiveLink(null)
        editable.addEventListener('mouseover', over)
        editable.addEventListener('mouseleave', leave)
        return () => {
            editable.removeEventListener('mouseover', over)
            editable.removeEventListener('mouseleave', leave)
        }
    }, [])

    // Reflect the active index onto the matching link in the text. The class never
    // reaches saved HTML — wp_kses strips class from <a>, and it's cleared here.
    useEffect(() => {
        editableLinks().forEach((a, i) => a.classList.toggle('is-link-active', i === activeLink))
    }, [activeLink, item.text])

    return (
        <div className="bulletin-item-row" ref={rowRef}>
            <div className="bulletin-item-fields">
                <div className="bulletin-label-row">
                    <input type="text" className="bulletin-item-label" placeholder="Krátky nadpis oznamu – nepovinné"
                        value={item.label} onChange={(e) => onChange(item.id, { label: e.target.value })} />
                    {canSuggest && (
                        <button type="button" className="button bulletin-suggest" title="Navrhnúť nadpis pomocou AI"
                            disabled={suggesting || !hasText} onClick={suggest}>
                            {suggesting ? 'Navrhujem…' : '✨ Navrhnúť'}
                        </button>
                    )}
                </div>
                <RichText html={item.text} placeholder="Text oznamu…"
                    onChange={(html) => onChange(item.id, { text: html })}
                    onSplit={(rest) => onSplit(item.id, rest)} />
                {links.length > 0 && (
                    <div className="bulletin-item-links">
                        {links.map((url, i) => (
                            <LinkQr key={`${url}#${i}`} number={i + 1} url={url} state={shortLinks[url]}
                                active={activeLink === i} onHover={(on) => setActiveLink(on ? i : null)} />
                        ))}
                    </div>
                )}
            </div>
            <div className="bulletin-row-controls">
                <button type="button" title="Hore" disabled={index === 0} onClick={() => onMove(index, -1)}>↑</button>
                <button type="button" title="Dole" disabled={index === total - 1} onClick={() => onMove(index, 1)}>↓</button>
                <button type="button" className="bulletin-remove" title="Zmazať" onClick={() => onRemove(item.id)}>✕</button>
            </div>
        </div>
    )
}

function LiturgyRow({ row, sundayYmd, onChange, onRemove }) {
    const invalid = (row.day || row.month) && !weekdayOf(row.day, row.month, sundayYmd)

    return (
        <div className="bulletin-lit-row">
            <input type="number" min="1" max="31" className="bulletin-lit-num" placeholder="deň"
                value={row.day} onChange={(e) => onChange(row.id, { day: clampInt(e.target.value, 31) })} />
            <span className="bulletin-lit-dot">.</span>
            <input type="number" min="1" max="12" className="bulletin-lit-num" placeholder="mes."
                value={row.month} onChange={(e) => onChange(row.id, { month: clampInt(e.target.value, 12) })} />
            <span className="bulletin-lit-dot">.</span>
            <input type="text" className="bulletin-lit-text" placeholder="spomienka sv. …"
                value={row.text} onChange={(e) => onChange(row.id, { text: e.target.value })} />
            {invalid && <span className="bulletin-lit-invalid">neplatný dátum</span>}
            <button type="button" className="bulletin-remove" title="Zmazať" onClick={() => onRemove(row.id)}>✕</button>
        </div>
    )
}

function App({ initial }) {
    const [date, setDate] = useState(initial.date || nextSunday())
    const [items, setItems] = useState(() => (initial.items?.length ? initial.items.map(asItem) : [newItem()]))
    const [liturgy, setLiturgy] = useState(() => (initial.liturgy || []).map(asLiturgy))
    const [loading, setLoading] = useState(false)
    const [headerHost, setHeaderHost] = useState(null) // DOM node above the WP title, target for the date + KBS control
    const [publishHost, setPublishHost] = useState(null) // DOM node in the Publish box, target for the view/PDF links
    const [kbsDays, setKbsDays] = useState(null) // loaded KBS week, kept so type checkboxes can add/remove rows
    const [types, setTypes] = useState(() => {
        const saved = initial.types && !Array.isArray(initial.types) ? initial.types : null
        return Object.fromEntries(TYPE_ORDER.map((t) => [t, saved ? !!saved[t] : CORE_TYPES.includes(t)]))
    })
    const [availableTypes, setAvailableTypes] = useState(() => (Array.isArray(initial.availableTypes) ? initial.availableTypes : []))
    // Seeded from the links persisted on each announcement so existing QR codes
    // show instantly; only new/changed links are fetched live below.
    const [shortLinks, setShortLinks] = useState(() => {
        const seed = {}
        ;(initial.items || []).forEach((i) => (i.links || []).forEach((l) => {
            if (l.url && l.shortUrl && l.qr) seed[l.url] = { status: 'ok', shortUrl: l.shortUrl, qr: l.qr }
        }))
        return seed
    }) // { [longUrl]: { status:'loading'|'ok'|'error', shortUrl, qr } }
    const shortLinksRef = useRef(shortLinks)
    shortLinksRef.current = shortLinks

    const fetchKbs = async () => {
        const res = await fetch(`${initial.restUrl}bulletins/liturgy?sunday=${encodeURIComponent(date)}`, { headers: { 'X-WP-Nonce': initial.nonce } })
        if (!res.ok) throw new Error('http ' + res.status)
        return res.json()
    }

    // Ask the server (Claude Haiku) to propose a short heading for an announcement.
    const suggestTitle = async (html) => {
        try {
            const res = await fetch(`${initial.restUrl}bulletins/suggest-title`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': initial.nonce },
                body: JSON.stringify({ text: html }),
            })
            if (!res.ok) return null
            const data = await res.json()
            return data.ok ? data.title : null
        } catch (e) {
            return null
        }
    }

    // Mount the portaled controls: date + KBS above the title, view/PDF links in the Publish box.
    useEffect(() => {
        setHeaderHost(titleHeaderHost())
        setPublishHost(publishActionsHost())
    }, [])

    // Live short URL + QR preview: whenever the announcements settle, shorten any
    // link we haven't seen yet (the server re-derives the canonical set on save).
    useEffect(() => {
        const timer = setTimeout(() => {
            const urls = [...new Set(items.flatMap((i) => extractLinks(i.text)))]
                .filter((u) => !shortLinksRef.current[u])
            if (!urls.length) return

            setShortLinks((prev) => ({ ...prev, ...Object.fromEntries(urls.map((u) => [u, { status: 'loading' }])) }))
            urls.forEach((url) => {
                fetch(`${initial.restUrl}bulletins/shorten?url=${encodeURIComponent(url)}`, { headers: { 'X-WP-Nonce': initial.nonce } })
                    .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
                    .then((data) => setShortLinks((prev) => ({
                        ...prev,
                        [url]: data.ok ? { status: 'ok', shortUrl: data.shortUrl, qr: data.qr } : { status: 'error' },
                    })))
                    .catch(() => setShortLinks((prev) => ({ ...prev, [url]: { status: 'error' } })))
            })
        }, 700)
        return () => clearTimeout(timer)
    }, [items])

    const updateItem = (id, patch) => setItems((prev) => prev.map((i) => (i.id === id ? { ...i, ...patch } : i)))
    const removeItem = (id) => setItems((prev) => (prev.length > 1 ? prev.filter((i) => i.id !== id) : [newItem()]))
    const addItem = () => setItems((prev) => [...prev, newItem()])
    const moveItem = (index, dir) => setItems((prev) => move(prev, index, dir))

    // A multi-paragraph paste: the extra paragraphs become new announcements after this one.
    const splitItem = (afterId, paragraphs) => setItems((prev) => {
        const idx = prev.findIndex((i) => i.id === afterId)
        if (idx < 0) return prev
        const inserts = paragraphs.map((text) => ({ ...newItem(), text }))
        return [...prev.slice(0, idx + 1), ...inserts, ...prev.slice(idx + 1)]
    })

    // Editing a row makes it "manual" (pristine=false) so type toggles no longer touch it.
    const updateLit = (id, patch) => setLiturgy((prev) => prev.map((r) => (r.id === id ? { ...r, ...patch, pristine: false } : r)))
    const removeLit = (id) => setLiturgy((prev) => prev.filter((r) => r.id !== id))
    const addLit = () => setLiturgy((prev) => [...prev, asLiturgy({})])

    const kbsRow = (day, di, o, oi) => ({ ...asLiturgy({ day: day.day, month: day.month, text: o.text }), type: o.type, kbsKey: `${di}_${oi}`, pristine: true })

    const addKbsRows = (rows, days, predicate) => {
        const have = new Set(rows.map((r) => r.kbsKey).filter(Boolean))
        const add = []
        days.forEach((d, di) => d.options.forEach((o, oi) => {
            if (predicate(o) && !have.has(`${di}_${oi}`)) add.push(kbsRow(d, di, o, oi))
        }))
        return [...rows, ...add].sort(byDate(date))
    }

    // Load both the Sunday title and the week's liturgy from KBS in one call.
    // Hand-added & edited liturgy rows stay; only pristine ones are refreshed.
    const loadFromKbs = async () => {
        setLoading(true)
        try {
            const data = await fetchKbs()

            if (data?.sundayName) {
                const ti = titleInput()
                if (ti) { ti.value = data.sundayName; ti.dispatchEvent(new Event('input', { bubbles: true })) }
            }

            const days = data.days || []
            if (!days.length) {
                window.alert('Liturgický kalendár nevrátil žiadne dni pre tento týždeň.')
            } else {
                setKbsDays(days)
                setAvailableTypes(typesIn(days))
                setLiturgy((prev) => addKbsRows(prev.filter((r) => !r.pristine), days, (o) => types[o.type]))
            }
        } catch (e) {
            window.alert('Nepodarilo sa načítať z KBS.')
        }
        setLoading(false)
    }

    // Checking a type adds its untouched KBS rows; unchecking removes them. Manual & edited rows stay.
    const toggleType = async (type) => {
        const enabled = !types[type]
        setTypes((prev) => ({ ...prev, [type]: enabled }))

        let days = kbsDays
        if (enabled && !days) {
            try { days = (await fetchKbs()).days || []; setKbsDays(days); setAvailableTypes(typesIn(days)) } catch (e) { days = null }
        }

        setLiturgy((prev) => enabled
            ? (days ? addKbsRows(prev, days, (o) => o.type === type) : prev)
            : prev.filter((r) => !(r.type === type && r.pristine)))
    }

    const weekday = weekdayName(date)
    const dateIsSunday = isSundayYmd(date)

    return (
        <div className="bulletin-editor">
            <input type="hidden" readOnly name={`${initial.metaboxId}[date]`} value={date} />
            <input type="hidden" readOnly name={`${initial.metaboxId}[items]`} value={JSON.stringify(items.map((i) => ({ label: i.label, text: i.text })))} />
            <input type="hidden" readOnly name={`${initial.metaboxId}[liturgy]`} value={JSON.stringify(liturgy.map((r) => ({ day: r.day, month: r.month, text: r.text, type: r.type, kbsKey: r.kbsKey, pristine: r.pristine })))} />
            <input type="hidden" readOnly name={`${initial.metaboxId}[types]`} value={JSON.stringify(types)} />
            <input type="hidden" readOnly name={`${initial.metaboxId}[availableTypes]`} value={JSON.stringify(availableTypes)} />

            {headerHost && createPortal(
                <>
                    <div className="bulletin-field">
                        <label>Nedeľa (dátum oznamov)</label>
                        <div className="bulletin-date-line">
                            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                            {weekday && <span className={`bulletin-weekday${dateIsSunday ? '' : ' is-warning'}`}>{weekday}</span>}
                            <button type="button" className="button button-secondary" onClick={loadFromKbs} disabled={loading}>
                                {loading ? 'Načítavam…' : 'Načítať z KBS'}
                            </button>
                        </div>
                        {weekday && !dateIsSunday && (
                            <p className="bulletin-warning">Vybraný deň je {weekday.toLowerCase()} — dátum oznamov by mala byť nedeľa.</p>
                        )}
                        <p className="bulletin-header-help">Načíta sa nadpis (názov nedele) a liturgický kalendár týždňa z KBS.</p>
                    </div>
                    <label className="bulletin-title-label" htmlFor="title">Nadpis</label>
                </>,
                headerHost
            )}

            {publishHost && initial.viewUrl && createPortal(
                initial.published ? (
                    <div className="bulletin-publish-links">
                        <a className="button button-secondary" href={initial.viewUrl} target="_blank" rel="noopener noreferrer">Zobraziť na webe</a>
                        <a className="button button-secondary" href={initial.pdfUrl} target="_blank" rel="noopener noreferrer">Stiahnuť PDF</a>
                    </div>
                ) : (
                    <span className="bulletin-topbar-hint">Odkaz na web a PDF budú dostupné po publikovaní.</span>
                ),
                publishHost
            )}

            <div className="bulletin-section">
                <h3>Liturgia týždňa</h3>

                {availableTypes.length > 0 && (
                    <>
                        <div className="bulletin-types">
                            {availableTypes.map((t) => (
                                <label key={t} className="bulletin-type">
                                    <input type="checkbox" checked={!!types[t]} onChange={() => toggleType(t)} /> {t}
                                </label>
                            ))}
                        </div>
                        <p className="bulletin-types-help">Zaškrtnuté typy slávení sa preberú z KBS, odškrtnuté sa odoberú. Ručne pridané a upravené riadky zostávajú.</p>
                    </>
                )}

                {liturgy.length > 0 && (
                    <div className="bulletin-lit-row bulletin-lit-head">
                        <span className="bulletin-lit-num">deň</span>
                        <span className="bulletin-lit-dot" />
                        <span className="bulletin-lit-num">mesiac</span>
                        <span className="bulletin-lit-dot" />
                        <span className="bulletin-lit-text">text</span>
                    </div>
                )}

                {liturgy.map((r) => (
                    <LiturgyRow key={r.id} row={r} sundayYmd={date} onChange={updateLit} onRemove={removeLit} />
                ))}
                <button type="button" className="button" onClick={addLit}>+ pridať deň</button>
            </div>

            <div className="bulletin-section">
                <h3>Oznamy</h3>
                {items.map((item, idx) => (
                    <ItemRow key={item.id} item={item} index={idx} total={items.length} onChange={updateItem} onRemove={removeItem} onMove={moveItem} onSplit={splitItem} shortLinks={shortLinks} onSuggestTitle={suggestTitle} canSuggest={!!initial.aiTitles} />
                ))}
                <button type="button" className="button button-secondary" onClick={addItem}>+ pridať oznam</button>
            </div>
        </div>
    )
}

// Make bold use <b> instead of an inline style (which wp_kses would strip).
try { document.execCommand('styleWithCSS', false, false) } catch (e) { /* noop */ }

const node = document.getElementById('bulletin-app')
const dataNode = document.getElementById('bulletin-data')
if (node && dataNode) {
    let initial = {}
    try { initial = JSON.parse(dataNode.textContent || '{}') } catch (e) { initial = {} }
    createRoot(node).render(<App initial={initial} />)
}
