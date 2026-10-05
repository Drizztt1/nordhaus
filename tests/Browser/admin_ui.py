"""
Test klikany w panelu admina (Playwright, przeglądarka bez okna) na instancji dev.
Uruchomienie: tests/Browser/run.sh  (dane testowe tworzy i usuwa fixtures.php, zrzuty w tests/Browser/screenshots)
"""
import json, os, sys
from playwright.sync_api import sync_playwright

ids = json.loads(sys.argv[1])
results = []

def check(label, expected, actual):
    ok = expected == actual
    results.append(ok)
    print(f"[{'OK' if ok else 'FAIL'}] {label}" + ("" if ok else f" expected {expected!r}, got {actual!r}"))

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1600, "height": 1000})
    page_errors = []
    page.on("pageerror", lambda err: page_errors.append(str(err)))
    page.goto("http://localhost/admin/login")
    page.fill("input[name=username]", os.environ.get("ADMIN_USER", "admin"))
    page.fill("input[name=password]", os.environ.get("ADMIN_PASSWORD", "admin"))
    page.click("button[type=submit]")
    page.wait_for_function("window.pimcore && pimcore.globalmanager && pimcore.globalmanager.get('layout_object_tree')", timeout=60000)

    def open_object(oid):
        page.evaluate(f"pimcore.helpers.closeObject({oid})")
        page.wait_for_timeout(700)
        page.evaluate(f"pimcore.helpers.openObject({oid}, 'object')")
        page.wait_for_function(f"(() => {{ const o = pimcore.globalmanager.get('object_{oid}'); return o && o.edit && o.tab && o.tab.query('field[name=sku]').length > 0; }})()", timeout=30000)
        page.wait_for_timeout(500)
        render_all_tabs(oid)

    def render_all_tabs(oid):
        # ExtJS renderuje zakładki leniwie: przeklikaj wszystkie (jak edytor), potem wróć do pierwszych
        page.evaluate(f"""(() => {{
            const tab = pimcore.globalmanager.get('object_{oid}').tab;
            for (let round = 0; round < 3; round++) {{
                tab.query('tabpanel').forEach(tp => {{ tp.items.each(i => tp.setActiveTab(i)); tp.setActiveTab(0); }});
            }}
        }})()""")
        page.wait_for_timeout(500)

    def field_input_id(oid, name):
        # pierwsze pole o tej nazwie w zakładce obiektu (dla pól lokalizowanych: pierwszy język = en)
        return page.evaluate(f"pimcore.globalmanager.get('object_{oid}').tab.query('field[name={name}]')[0].inputEl.id")

    def field_value(oid, name):
        return page.evaluate(f"pimcore.globalmanager.get('object_{oid}').tab.query('field[name={name}]')[0].getValue()")

    def type_into(oid, name, value):
        # pole może być na nieaktywnej zakładce layoutu: najpierw ją pokaż
        page.evaluate(f"""(() => {{
            let c = pimcore.globalmanager.get('object_{oid}').tab.query('field[name={name}]')[0];
            for (let p = c; p; p = p.ownerCt) {{ if (p.ownerCt && p.ownerCt.setActiveTab) p.ownerCt.setActiveTab(p); }}
        }})()""")
        page.wait_for_timeout(300)
        sel = "#" + field_input_id(oid, name)
        page.fill(sel, str(value))
        page.press(sel, "Tab")

    def click_publish(oid):
        btn = page.evaluate(f"pimcore.globalmanager.get('object_{oid}').toolbarButtons.publish.id")
        mark_current(oid)
        page.click(f"#{btn}-btnInnerEl")
        return None

    def mark_current(oid):
        page.evaluate(f"(() => {{ window.__before = pimcore.globalmanager.get('object_{oid}'); return true; }})()")

    def wait_reloaded(oid, _unused=None):
        try:
            page.wait_for_function(f"(() => {{ const o = pimcore.globalmanager.get('object_{oid}'); return o && o !== window.__before && o.edit && o.tab && o.tab.query('field[name=sku]').length > 0; }})()", timeout=30000)
        except Exception:
            page.screenshot(path=f"/work/screenshots/FAIL-reload-{oid}.png")
            raise
        # ostrzeżenia z wbudowanego autosave Pimcore (timer po zmianie pól) to zachowanie core, nie naszego kodu
        ours = page.evaluate("window.__notes.filter(n => n.includes('Another saving') && !n.includes('saveAutoVersion')).length")
        check(f"no 'another saving process' warning caused by our reload ({oid})", 0, ours)
        page.wait_for_timeout(500)
        render_all_tabs(oid)

    page.evaluate(r"""(() => {
        window.__notes = [];
        const orig = pimcore.helpers.showNotification;
        pimcore.helpers.showNotification = function (title, text) {
            window.__notes.push(text + ' || ' + new Error().stack.replace(/\s+/g, ' ').slice(0, 900));
            return orig.apply(this, arguments);
        };
        return true;
    })()""")

    # A: edytor zmienia stock wariantu i publikuje -> formularz sam pokazuje availability = in_stock
    v = ids["variant"]
    open_object(v)
    check("A variant availability before", "out_of_stock", field_value(v, "availability"))
    type_into(v, "stock", 5)
    old = click_publish(v)
    wait_reloaded(v, old)
    check("A form reloaded after save and shows in_stock", "in_stock", field_value(v, "availability"))
    page.screenshot(path="/work/screenshots/A-variant-after-save.png")

    # B: rodzic pokazuje in_stock po zmianie wariantu
    open_object(ids["sofa"])
    check("B parent availability in admin", "in_stock", field_value(ids["sofa"], "availability"))

    # C: duplikat SKU -> zapis zablokowany, czytelny komunikat
    c = ids["chair"]
    open_object(c)
    type_into(c, "sku", "  ui-sofa ")
    click_publish(c)
    page.wait_for_selector("text=already used by product", timeout=15000)
    msg = page.inner_text("body")
    check("C duplicate SKU error shown to editor", True, 'SKU "UI-SOFA" is already used by product "/ui-test/sofa"' in msg)
    page.screenshot(path="/work/screenshots/C-duplicate-sku.png")
    page.keyboard.press("Escape")
    page.evaluate("Ext.WindowManager.each(w => { if (w.isWindow && w.isVisible()) w.close(); })")

    # D: szkic: wpisanie nazwy -> po zapisie od razu widać wygenerowany urlKey i znormalizowane SKU
    d = ids["draft"]
    open_object(d)
    type_into(d, "name", "Łóżko Comfort XL")
    type_into(d, "sku", " ui-draft-x ")
    btn = page.evaluate(f"pimcore.globalmanager.get('object_{d}').toolbarButtons.save.id")
    mark_current(d)
    page.click(f"#{btn}-btnInnerEl")
    wait_reloaded(d)
    check("D url key generated and visible right after save", "lozko-comfort-xl", field_value(d, "urlKey"))
    check("D SKU normalised and visible right after save", "UI-DRAFT-X", field_value(d, "sku"))
    page.screenshot(path="/work/screenshots/D-draft-url-key.png")


    # F: tabela z briefu "Where it is filled" w panelu: wariant i rodzic dostają własne custom layouty
    def layout_of(oid):
        return page.evaluate(f"pimcore.globalmanager.get('object_{oid}').data.currentLayoutId")

    def read_only(oid, name):
        return page.evaluate(f"""(() => {{ const f = pimcore.globalmanager.get('object_{oid}').tab.query('field[name={name}]')[0];
            return !!f && (f.readOnly === true || f.disabled === true); }})()""")

    def has_field(oid, name):
        return page.evaluate(f"pimcore.globalmanager.get('object_{oid}').tab.query('field[name={name}]').length > 0")

    open_object(v)
    check("F variant opens in the variant layout", "product_variant", layout_of(v))
    check("F variant: name read-only (inherited)", True, read_only(v, "name"))
    check("F variant: URL key read-only (inherited)", True, read_only(v, "urlKey"))
    check("F variant: stock editable", False, read_only(v, "stock"))
    page.screenshot(path="/work/screenshots/F-variant-layout.png")
    open_object(ids["chair"])
    check("F parent opens in the parent layout", "product_parent", layout_of(ids["chair"]))
    check("F parent: no stock field", False, has_field(ids["chair"], "stock"))
    check("F parent: name editable", False, read_only(ids["chair"], "name"))

    # E: "Zapisz, opublikuj i zamknij" z menu przycisku -> zapis bez błędów JS, zakładka zamknięta
    e = ids["sofa"]
    page.evaluate("Ext.WindowManager.each(w => { if (w.isWindow && w.isVisible()) w.close(); })")
    open_object(e)
    type_into(e, "sku", "ui-sofa-2")
    try:
        btn = page.evaluate(f"pimcore.globalmanager.get('object_{e}').toolbarButtons.publish.id")
        box = page.locator(f"#{btn}").bounding_box()  # strzałka SplitButtona = prawa krawędź przycisku
        page.mouse.click(box["x"] + box["width"] - 8, box["y"] + box["height"] / 2)
        page.wait_for_function(f"pimcore.globalmanager.get('object_{e}').toolbarButtons.publish.menu.isVisible()", timeout=5000)
        item = page.evaluate(f"""pimcore.globalmanager.get('object_{e}').toolbarButtons.publish.menu.items.items
            .find(i => /close|zamknij/i.test(i.text || '')).id""")
        errors_before = len(page_errors)
        page.click(f"#{item}")
        page.wait_for_function(f"!pimcore.globalmanager.get('object_{e}')", timeout=15000)
    except Exception:
        page.screenshot(path="/work/screenshots/FAIL-E.png")
        raise
    page.wait_for_timeout(1500)
    check("E save, publish & close: no JavaScript errors", [], page_errors[errors_before:])
    open_object(e)
    check("E save, publish & close: data saved", "UI-SOFA-2", field_value(e, "sku"))

    browser.close()

print("\nALL UI CHECKS PASSED" if all(results) else f"\n{results.count(False)} UI CHECK(S) FAILED")
sys.exit(0 if all(results) else 1)
