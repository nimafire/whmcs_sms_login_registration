
<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

add_hook("ClientAreaFooterOutput", 1, function ($vars) {
    try {
        $template = strtolower((string) ($vars["templatefile"] ?? ""));
        $filename = strtolower((string) ($vars["filename"] ?? ""));
        $isLogin = $template === "login" || $filename === "login";
        $isRegister = $template === "register" || $filename === "register";

        if (!$isLogin && !$isRegister) {
            return "";
        }

        if (!\WHMCS\Database\Capsule::table("tbladdonmodules")->where("module", "sms_login_registration")->exists()) {
            return "";
        }

        $rows = \WHMCS\Database\Capsule::table("mod_smslr_settings")
            ->whereIn("setting_key", ["enabled", "mode", "otp_length", "resend_interval", "mobile_field_id"])
            ->pluck("setting_value", "setting_key")
            ->all();

        if (($rows["enabled"] ?? "0") !== "1" || (int) ($rows["mobile_field_id"] ?? 0) <= 0) {
            return "";
        }

        $mode = in_array(($rows["mode"] ?? "both"), ["login", "register", "both"], true) ? $rows["mode"] : "both";
        $base = rtrim((string) ($vars["systemurl"] ?? ""), "/");

        $config = [
            "url" => ($base !== "" ? $base . "/" : "") . "index.php?m=sms_login_registration",
            "token" => function_exists("generate_token") ? (string) generate_token("plain") : "",
            "mode" => $mode,
            "initialPurpose" => $isRegister ? "register" : "login",
            "length" => max(4, min(8, (int) ($rows["otp_length"] ?? 6))),
            "resend" => max(10, (int) ($rows["resend_interval"] ?? 60))
        ];

        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return "";
        }
    } catch (\Throwable $e) {
        return "";
    }

    $assets = <<<'HTML'
<style>
#smslr-entry .smslr-entry-btn {
    width:100%;
    min-height:44px;
    background:#337ab7;
    border:1px solid #2e6da4;
    color:#fff;
    border-radius:6px;
    font-size:14px;
    font-weight:600;
    padding:11px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    cursor:pointer;
    transition:background .2s ease,box-shadow .2s ease;
}

#smslr-entry .smslr-entry-btn:hover {
    background:#286090;
    border-color:#204d74;
    color:#fff;
    box-shadow:0 4px 12px rgba(51,122,183,.25);
}

#smslr-entry .smslr-entry-btn i {
    font-size:20px;
    line-height:1;
}

#smslr-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:99999;padding:16px;direction:rtl;text-align:right}
#smslr-overlay.smslr-open{display:flex}
#smslr-overlay .smslr-box{background:#fff;color:#222;border-radius:12px;width:100%;max-width:420px;max-height:92vh;overflow:auto;padding:24px;position:relative;box-shadow:0 10px 40px rgba(0,0,0,.3);box-sizing:border-box}
#smslr-overlay *{box-sizing:border-box}
#smslr-overlay .smslr-close{position:absolute;top:10px;left:14px;background:none;border:0;font-size:26px;line-height:1;cursor:pointer;color:#777}
#smslr-overlay h3{margin:0 0 16px;font-size:18px;text-align:center}
#smslr-overlay .smslr-tabs{display:flex;gap:6px;margin-bottom:16px}
#smslr-overlay .smslr-tab{flex:1;padding:8px;border:1px solid #ccc;background:#f5f5f5;border-radius:6px;cursor:pointer;font-size:14px}
#smslr-overlay .smslr-tab.smslr-active{background:#337ab7;color:#fff;border-color:#337ab7}
#smslr-overlay .smslr-field{margin-bottom:12px}
#smslr-overlay label{display:block;margin-bottom:4px;font-size:13px;color:#555}
#smslr-overlay input.smslr-input{width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;font-size:15px}
#smslr-overlay .smslr-btn{width:100%;padding:11px;border:0;border-radius:6px;background:#337ab7;color:#fff;font-size:15px;cursor:pointer}
#smslr-overlay .smslr-btn[disabled]{opacity:.55;cursor:not-allowed}
#smslr-overlay .smslr-link{background:none;border:0;color:#337ab7;cursor:pointer;padding:4px;font-size:13px}
#smslr-overlay .smslr-link[disabled]{color:#999;cursor:default}
#smslr-overlay .smslr-msg{min-height:20px;margin-bottom:10px;font-size:13px;text-align:center}
#smslr-overlay .smslr-msg.smslr-err{color:#c0392b}
#smslr-overlay .smslr-msg.smslr-ok{color:#1e8449}
#smslr-overlay .smslr-info{text-align:center;font-size:13px;color:#555;margin-bottom:14px}
#smslr-overlay .smslr-codes{display:flex;gap:8px;justify-content:center;direction:ltr;margin-bottom:14px}
#smslr-overlay .smslr-codes input{width:42px;height:50px;text-align:center;font-size:22px;border:1px solid #ccc;border-radius:6px;padding:0}
#smslr-overlay .smslr-codes input:focus,#smslr-overlay input.smslr-input:focus{outline:0;border-color:#337ab7;box-shadow:0 0 0 2px rgba(51,122,183,.25)}
#smslr-overlay .smslr-row{display:flex;justify-content:space-between;margin-top:10px}
</style>
<script>
(function () {
    var C = window.SMSLR;
    if (!C || window.__smslrReady) {
        return;
    }
    window.__smslrReady = true;

    var state = { purpose: "login", phone: "", busy: false, timer: null, opener: null };
    var overlay, box, msg, tabs = {}, views = {}, codeBoxes = [], resendBtn, verifyBtn, infoEl;

    function toLatin(value) {
        return String(value).replace(/[\u06F0-\u06F9\u0660-\u0669]/g, function (ch) {
            var c = ch.charCodeAt(0);
            return String(c >= 1776 ? c - 1776 : c - 1632);
        });
    }

    function h(tag, props, kids) {
        var node = document.createElement(tag);
        var key;
        props = props || {};
        for (key in props) {
            if (key === "text") {
                node.textContent = props[key];
            } else if (key === "class") {
                node.className = props[key];
            } else {
                node.setAttribute(key, props[key]);
            }
        }
        (kids || []).forEach(function (kid) {
            node.appendChild(kid);
        });
        return node;
    }

    function field(label, name, type, extra) {
        var attrs = { "class": "smslr-input", type: type, name: name, autocomplete: "off" };
        var key;
        for (key in (extra || {})) {
            attrs[key] = extra[key];
        }
        var input = h("input", attrs);
        return { wrap: h("div", { "class": "smslr-field" }, [h("label", { text: label }), input]), input: input };
    }

    function setMsg(text, kind) {
        msg.textContent = text || "";
        msg.className = "smslr-msg" + (kind ? " smslr-" + kind : "");
    }

    function setBusy(flag) {
        state.busy = flag;
        Array.prototype.forEach.call(box.querySelectorAll(".smslr-btn"), function (b) {
            b.disabled = flag;
        });
    }

    function getToken() {
        var input = document.querySelector('input[name="token"]');
        return input && input.value ? input.value : C.token;
    }

    function endpoint() {
        return C.url.replace(/^https?:/i, window.location.protocol);
    }

    function post(data) {
        var body = new URLSearchParams();
        var key;
        for (key in data) {
            body.append(key, data[key]);
        }
        body.append("token", getToken());
        return fetch(endpoint(), {
            method: "POST",
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" },
            body: body
        }).then(function (res) {
            return res.text();
        }).then(function (text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                return { ok: false, message: "پاسخ نامعتبر از سرور. صفحه را رفرش کنید و دوباره تلاش کنید." };
            }
        }).catch(function () {
            return { ok: false, message: "ارتباط با سرور برقرار نشد." };
        });
    }

    function show(name) {
        Object.keys(views).forEach(function (k) {
            views[k].style.display = k === name ? "" : "none";
        });
        var showTabs = name !== "code" && C.mode === "both";
        box.querySelector(".smslr-tabs").style.display = showTabs ? "" : "none";
        setMsg("");
    }

    function selectTab(purpose) {
        state.purpose = purpose;
        Object.keys(tabs).forEach(function (k) {
            tabs[k].className = "smslr-tab" + (k === purpose ? " smslr-active" : "");
        });
        show(purpose);
        var first = views[purpose].querySelector("input");
        if (first) {
            first.focus();
        }
    }

    function startTimer(seconds) {
        clearInterval(state.timer);
        var left = seconds;
        function tick() {
            if (left > 0) {
                resendBtn.disabled = true;
                resendBtn.textContent = "ارسال مجدد کد (" + left + " ثانیه)";
                left -= 1;
            } else {
                clearInterval(state.timer);
                resendBtn.disabled = false;
                resendBtn.textContent = "ارسال مجدد کد";
            }
        }
        tick();
        state.timer = setInterval(tick, 1000);
    }

    function collectSend() {
        var view = views[state.purpose];
        var data = { smslr_action: "send_" + state.purpose };
        Array.prototype.forEach.call(view.querySelectorAll("input"), function (input) {
            data[input.name] = toLatin(input.value).trim();
        });
        return data;
    }

    function afterSend(res) {
        if (!res.ok) {
            setMsg(res.message, "err");
            return false;
        }
        state.phone = res.phone || state.phone;
        buildCodeBoxes(res.length || C.length);
        infoEl.textContent = "کد تأیید به شماره " + state.phone + " ارسال شد.";
        show("code");
        setMsg(res.message, "ok");
        startTimer(res.resend || C.resend);
        codeBoxes[0].focus();
        return true;
    }

    function sendCode() {
        if (state.busy) {
            return;
        }
        var data = collectSend();
        state.phone = data.phone || "";
        setMsg("");
        setBusy(true);
        post(data).then(function (res) {
            setBusy(false);
            afterSend(res);
        });
    }

    function resendCode() {
        if (state.busy) {
            return;
        }
        setBusy(true);
        var data = collectSend();
        post(data).then(function (res) {
            setBusy(false);
            if (res.ok) {
                clearCodes();
                startTimer(res.resend || C.resend);
                setMsg(res.message, "ok");
            } else {
                setMsg(res.message, "err");
                if (res.wait) {
                    startTimer(res.wait);
                }
            }
        });
    }

    function codeValue() {
        return codeBoxes.map(function (b) { return b.value; }).join("");
    }

    function clearCodes() {
        codeBoxes.forEach(function (b) { b.value = ""; });
        if (codeBoxes[0]) {
            codeBoxes[0].focus();
        }
    }

    function verify() {
        if (state.busy) {
            return;
        }
        var code = codeValue();
        if (code.length !== codeBoxes.length) {
            setMsg("کد را کامل وارد کنید.", "err");
            return;
        }
        setMsg("");
        setBusy(true);
        post({ smslr_action: "verify", code: code }).then(function (res) {
            if (res.ok && res.redirect) {
                setMsg(res.message, "ok");
                window.location.href = res.redirect;
                return;
            }
            setBusy(false);
            setMsg(res.message, "err");
            if (res.restart) {
                selectTab(state.purpose);
            } else {
                clearCodes();
            }
        });
    }

    function fillFrom(start, digits) {
        var i;
        for (i = 0; i < digits.length && start + i < codeBoxes.length; i++) {
            codeBoxes[start + i].value = digits.charAt(i);
        }
        var next = Math.min(start + digits.length, codeBoxes.length - 1);
        codeBoxes[next].focus();
        if (codeValue().length === codeBoxes.length) {
            verify();
        }
    }

    function buildCodeBoxes(n) {
        var holder = views.code.querySelector(".smslr-codes");
        holder.innerHTML = "";
        codeBoxes = [];
        var i;
        for (i = 0; i < n; i++) {
            (function (index) {
                var input = h("input", { type: "text", inputmode: "numeric", maxlength: n, autocomplete: index === 0 ? "one-time-code" : "off", "aria-label": "رقم " + (index + 1) });
                input.addEventListener("input", function () {
                    var digits = toLatin(input.value).replace(/\D/g, "");
                    input.value = "";
                    if (digits) {
                        fillFrom(index, digits);
                    }
                });
                input.addEventListener("keydown", function (e) {
                    if (e.key === "Backspace" && input.value === "" && index > 0) {
                        codeBoxes[index - 1].value = "";
                        codeBoxes[index - 1].focus();
                        e.preventDefault();
                    } else if (e.key === "ArrowLeft" && index > 0) {
                        codeBoxes[index - 1].focus();
                    } else if (e.key === "ArrowRight" && index < n - 1) {
                        codeBoxes[index + 1].focus();
                    } else if (e.key === "Enter") {
                        verify();
                    }
                });
                input.addEventListener("paste", function (e) {
                    var text = (e.clipboardData || window.clipboardData).getData("text");
                    var digits = toLatin(text).replace(/\D/g, "").slice(0, n);
                    e.preventDefault();
                    if (digits) {
                        fillFrom(0, digits);
                    }
                });
                input.addEventListener("focus", function () {
                    input.select();
                });
                codeBoxes.push(input);
                holder.appendChild(input);
            })(i);
        }
    }

    function open(opener, purpose) {
        state.opener = opener || null;
        overlay.className = "smslr-open";
        selectTab(purpose || C.initialPurpose || (C.mode === "register" ? "register" : "login"));
    }

    function close() {
        clearInterval(state.timer);
        overlay.className = "";
        if (state.opener) {
            state.opener.focus();
        }
    }

    function build() {
        var login = field("شماره همراه", "phone", "tel", { placeholder: "09123456789", inputmode: "numeric", dir: "ltr" });
        var loginBtn = h("button", { type: "button", "class": "smslr-btn", text: "دریافت کد ورود" });
        views.login = h("div", {}, [login.wrap, loginBtn]);

        var fn = field("نام", "firstname", "text");
        var ln = field("نام خانوادگی", "lastname", "text");
        var em = field("ایمیل", "email", "email", { dir: "ltr" });
        var ph = field("شماره همراه", "phone", "tel", { placeholder: "09123456789", inputmode: "numeric", dir: "ltr" });
        var regBtn = h("button", { type: "button", "class": "smslr-btn", text: "ثبت‌نام و دریافت کد" });
        views.register = h("div", {}, [fn.wrap, ln.wrap, em.wrap, ph.wrap, regBtn]);

        infoEl = h("div", { "class": "smslr-info" });
        verifyBtn = h("button", { type: "button", "class": "smslr-btn", text: "تأیید و ورود" });
        resendBtn = h("button", { type: "button", "class": "smslr-link", text: "ارسال مجدد کد" });
        var changeBtn = h("button", { type: "button", "class": "smslr-link", text: "تغییر شماره" });
        views.code = h("div", {}, [infoEl, h("div", { "class": "smslr-codes" }), verifyBtn, h("div", { "class": "smslr-row" }, [resendBtn, changeBtn])]);

        tabs.login = h("button", { type: "button", "class": "smslr-tab", text: "ورود" });
        tabs.register = h("button", { type: "button", "class": "smslr-tab", text: "ثبت‌نام" });
        var tabRow = h("div", { "class": "smslr-tabs" }, [tabs.login, tabs.register]);

        msg = h("div", { "class": "smslr-msg", role: "status", "aria-live": "polite" });
        var closeBtn = h("button", { type: "button", "class": "smslr-close", "aria-label": "بستن", text: "×" });
        box = h("div", { "class": "smslr-box", role: "dialog", "aria-modal": "true" }, [
            closeBtn,
            h("h3", { text: C.mode === "register" ? "ثبت‌نام با شماره همراه" : "ورود با شماره همراه" }),
            tabRow, msg, views.login, views.register, views.code
        ]);
        overlay = h("div", { id: "smslr-overlay" }, [box]);
        document.body.appendChild(overlay);

        closeBtn.addEventListener("click", close);
        overlay.addEventListener("mousedown", function (e) {
            if (e.target === overlay) {
                close();
            }
        });
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && overlay.className === "smslr-open") {
                close();
            }
        });
        tabs.login.addEventListener("click", function () { selectTab("login"); });
        tabs.register.addEventListener("click", function () { selectTab("register"); });
        loginBtn.addEventListener("click", sendCode);
        regBtn.addEventListener("click", sendCode);
        [login.input, fn.input, ln.input, em.input, ph.input].forEach(function (input) {
            input.addEventListener("keydown", function (e) {
                if (e.key === "Enter") {
                    e.preventDefault();
                    sendCode();
                }
            });
        });
        verifyBtn.addEventListener("click", verify);
        resendBtn.addEventListener("click", resendCode);
        changeBtn.addEventListener("click", function () {
            clearInterval(state.timer);
            selectTab(state.purpose);
        });
        tabs.login.style.display = C.mode === "register" ? "none" : "";
        tabs.register.style.display = C.mode === "login" ? "none" : "";
    }

    function insertButton() {
        if (document.getElementById("smslr-entry")) {
            return;
        }

        var form = document.querySelector('form[action*="dologin"]');
        if (!form) {
            var pass = document.querySelector('input[type="password"]');
            form = pass ? pass.closest("form") : null;
        }
        if (!form) {
            return;
        }

        var submit = form.querySelector('button[type="submit"], input[type="submit"]');
        var anchor = submit ? (submit.closest(".form-group") || submit.parentNode) : form;

        var icon = h("i", {
            "class": "fa fa-mobile-alt",
            "aria-hidden": "true"
        });

        var btn = h("button", {
            type: "button",
            "class": "btn btn-block btn-info smslr-entry-btn"
        }, [
            icon,
            h("span", {
                text: C.mode === "register" ? "ثبت‌نام با شماره همراه" : "ورود با شماره همراه"
            })
        ]);

        var wrap = h("div", {
            id: "smslr-entry",
            style: "margin-top:12px"
        }, [btn]);

        if (anchor === form) {
            form.appendChild(wrap);
        } else {
            anchor.parentNode.insertBefore(wrap, anchor.nextSibling);
        }

        btn.addEventListener("click", function (e) {
            e.preventDefault();
            open(btn);
        });
    }

    function init() {
        try {
            build();

            if (C.initialPurpose === "register") {
                open(null, "register");
                return;
            }

            insertButton();
        } catch (e) {
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
</script>
HTML;

    return "<script>window.SMSLR=" . $json . ";</script>" . $assets;
});
