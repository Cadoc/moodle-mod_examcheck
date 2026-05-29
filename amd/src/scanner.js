// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Camera-based QR/barcode scanner for marking students.
 *
 * Uses the native BarcodeDetector API when available (Android Chrome, desktop
 * Chrome/Edge) and falls back to the bundled ZXing decoder elsewhere (Safari,
 * iOS, Firefox, desktop webcams). A manual entry box is always available too and
 * works with USB / Bluetooth "keyboard wedge" scanners or by typing the value.
 *
 * @module     mod_examcheck/scanner
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {add as addToast} from 'core/toast';
import {getString} from 'core/str';
import ZXing from 'mod_examcheck/zxing';

const DETECT_INTERVAL = 350;
const DEDUPE_MS = 2500;

// Resolve the ZXing library across module-interop shapes, falling back to the global the
// vendored UMD also sets. Returns the object exposing BrowserMultiFormatReader, or null.
const zxinglib = (() => {
    const candidates = [ZXing, ZXing && ZXing.default, window.ZXing];
    return candidates.find((c) => c && c.BrowserMultiFormatReader) || null;
})();

let config = {cmid: 0, groupid: 0};
let root = null;
let detector = null;
let zxingReader = null;
let stream = null;
let detectTimer = null;
let scanning = false;
let pending = null; // {value} awaiting confirmation.
let lastValue = '';
let lastValueTime = 0;

/**
 * Initialise the scanner page.
 *
 * @param {Number} cmid Course module id.
 * @param {Number} groupid Group context.
 */
export const init = (cmid, groupid) => {
    root = document.querySelector('[data-region="examcheck-scanner"]');
    if (!root) {
        return;
    }
    config = {cmid, groupid};

    registerControls();
    detectFeatureSupport();
};

/**
 * Wire up the buttons and manual entry on the page.
 */
const registerControls = () => {
    root.querySelector('[data-action="startcamera"]')?.addEventListener('click', startCamera);
    root.querySelector('[data-action="stopcamera"]')?.addEventListener('click', stopCamera);
    root.querySelector('[data-action="confirm"]')?.addEventListener('click', confirmPending);
    root.querySelector('[data-action="next"]')?.addEventListener('click', resumeScanning);
    root.querySelector('[data-action="cancel"]')?.addEventListener('click', resumeScanning);

    const form = root.querySelector('[data-region="manualform"]');
    form?.addEventListener('submit', (e) => {
        e.preventDefault();
        const input = root.querySelector('[data-region="manualvalue"]');
        const value = input ? input.value : '';
        if (value.trim() !== '') {
            process(value);
            if (input) {
                input.value = '';
                input.focus();
            }
        }
    });
};

/**
 * Decide whether live camera scanning is possible and adjust the UI.
 *
 * Needs a camera (getUserMedia, which requires a secure/HTTPS context) and a
 * decoder: the native BarcodeDetector, or the bundled ZXing fallback.
 */
const detectFeatureSupport = () => {
    if ('BarcodeDetector' in window) {
        try {
            detector = new window.BarcodeDetector();
        } catch (e) {
            detector = null;
        }
    }
    const hascamera = Boolean(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    const candecode = detector || Boolean(zxinglib);
    if (!hascamera || !candecode) {
        toggle('[data-region="camerawrap"]', false);
        showStatus('cameraunsupported', 'info');
    }
};

/**
 * Start the camera and the detection loop (native detector or ZXing fallback).
 */
const startCamera = async() => {
    const video = root.querySelector('[data-region="video"]');
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: {facingMode: 'environment'},
            audio: false,
        });
    } catch (e) {
        showStatus('camerablocked', 'warning');
        return;
    }

    video.srcObject = stream;
    await video.play();

    toggle('[data-action="startcamera"]', false);
    toggle('[data-action="stopcamera"]', true);
    resumeScanning();

    if (detector) {
        detectTimer = window.setInterval(detectFrame, DETECT_INTERVAL);
    } else {
        startZxing(video);
    }
};

/**
 * Decode continuously from the playing video element using the ZXing fallback.
 *
 * @param {HTMLVideoElement} video The live video element.
 */
const startZxing = (video) => {
    try {
        zxingReader = new zxinglib.BrowserMultiFormatReader();
        const decoding = zxingReader.decodeFromVideoElement(video, (result) => {
            if (result) {
                window.console.log('[examcheck] ZXing decoded:', result.getText());
                if (scanning) {
                    process(result.getText());
                }
            }
        });
        // decodeFromVideoElement returns a promise that rejects if it cannot start.
        if (decoding && typeof decoding.catch === 'function') {
            decoding.catch((e) => window.console.log('[examcheck] ZXing decode could not start:', e));
        }
    } catch (e) {
        window.console.log('[examcheck] ZXing start failed:', e);
        zxingReader = null;
        showStatus('cameraunsupported', 'info');
    }
};

/**
 * Stop the camera and the detection loop.
 */
const stopCamera = () => {
    scanning = false;
    if (detectTimer) {
        window.clearInterval(detectTimer);
        detectTimer = null;
    }
    if (zxingReader) {
        try {
            zxingReader.reset();
        } catch (e) {
            // Reader already stopped; ignore.
        }
        zxingReader = null;
    }
    if (stream) {
        stream.getTracks().forEach((t) => t.stop());
        stream = null;
    }
    const video = root.querySelector('[data-region="video"]');
    if (video) {
        video.srcObject = null;
    }
    toggle('[data-action="startcamera"]', true);
    toggle('[data-action="stopcamera"]', false);
};

/**
 * Inspect the current video frame for codes.
 */
const detectFrame = async() => {
    if (!scanning || !detector) {
        return;
    }
    const video = root.querySelector('[data-region="video"]');
    if (!video || video.readyState < 2) {
        return;
    }
    let codes;
    try {
        codes = await detector.detect(video);
    } catch (e) {
        return;
    }
    if (codes && codes.length) {
        window.console.log('[examcheck] BarcodeDetector decoded:', codes[0].rawValue, codes[0].format);
        process(codes[0].rawValue);
    }
};

/**
 * Process a scanned or typed value.
 *
 * @param {String} value The raw value.
 */
const process = (value) => {
    const now = Date.now();
    // Ignore the same value scanned repeatedly in quick succession.
    if (value === lastValue && (now - lastValueTime) < DEDUPE_MS) {
        return;
    }
    lastValue = value;
    lastValueTime = now;

    const requireConfirm = isConfirmRequired();
    scanning = false; // Pause while we resolve this value.

    Ajax.call([{
        methodname: 'mod_examcheck_scan_lookup',
        args: {
            cmid: config.cmid,
            stepid: currentStep(),
            scanfield: currentField(),
            value: value,
            confirm: false,
            requireconfirm: requireConfirm,
            groupid: config.groupid,
        },
    }])[0].then((outcome) => {
        handleOutcome(outcome, value, requireConfirm);
        return outcome;
    }).catch((err) => {
        showMessage(err.message || String(err), 'danger');
        resumeScanning();
    });
};

/**
 * Act on the result of a scan lookup.
 *
 * @param {Object} outcome The web service outcome.
 * @param {String} value The scanned value (kept for the confirm step).
 * @param {Boolean} requireConfirm Whether confirmation is required this session.
 */
const handleOutcome = (outcome, value, requireConfirm) => {
    window.console.log('[examcheck] scan outcome:', outcome.status, '| value:', value,
        '| student:', outcome.userlabel || '(none)', '| message:', outcome.message);

    switch (outcome.status) {
        case 'needsconfirm':
            addToast(outcome.message, {type: 'info'});
            pending = {value};
            showPending(outcome.userlabel);
            break;
        case 'marked':
            addToast(outcome.message, {type: 'success'});
            showMessage(outcome.message, 'success');
            afterDefinitive(requireConfirm);
            break;
        case 'conflict':
            addToast(outcome.message, {type: 'warning'});
            showMessage(outcome.message, 'warning');
            afterDefinitive(requireConfirm);
            break;
        case 'notfound':
            // Surface the value that was read so a mis-scan is obvious.
            addToast(outcome.message, {type: 'warning'});
            showMessage(outcome.message, 'warning');
            resumeScanning();
            break;
        default:
            addToast(outcome.message, {type: 'info'});
            showMessage(outcome.message, 'info');
            resumeScanning();
    }
};

/**
 * Confirm and mark the pending student.
 */
const confirmPending = () => {
    if (!pending) {
        return;
    }
    const value = pending.value;
    pending = null;
    Ajax.call([{
        methodname: 'mod_examcheck_scan_lookup',
        args: {
            cmid: config.cmid,
            stepid: currentStep(),
            scanfield: currentField(),
            value: value,
            confirm: true,
            requireconfirm: true,
            groupid: config.groupid,
        },
    }])[0].then((outcome) => {
        if (outcome.status === 'marked') {
            showMessage(outcome.message, 'success');
        } else if (outcome.status === 'conflict') {
            showMessage(outcome.message, 'warning');
        } else {
            showMessage(outcome.message, 'info');
        }
        // In confirm mode we always wait for an explicit "scan next".
        showNext();
        return outcome;
    }).catch((err) => {
        addToast(err.message || String(err), {type: 'danger'});
        resumeScanning();
    });
};

/**
 * Behaviour after a definitive (marked/conflict) outcome.
 *
 * @param {Boolean} requireConfirm Whether the session waits for a click.
 */
const afterDefinitive = (requireConfirm) => {
    if (requireConfirm) {
        showNext();
    } else {
        resumeScanning();
    }
};

/**
 * Resume scanning for the next student and reset the result panel.
 */
const resumeScanning = () => {
    pending = null;
    toggle('[data-region="pending"]', false);
    toggle('[data-action="next"]', false);
    scanning = Boolean(stream); // Only auto-scan when the camera is running.
};

/**
 * Show the pending student awaiting confirmation.
 *
 * @param {String} name The student's full name.
 */
const showPending = (name) => {
    const region = root.querySelector('[data-region="pending"] [data-region="pendingname"]');
    if (region) {
        region.textContent = name;
    }
    toggle('[data-region="pending"]', true);
    toggle('[data-action="next"]', false);
};

/**
 * Show the "scan next" control and stop auto-scanning until clicked.
 */
const showNext = () => {
    scanning = false;
    toggle('[data-region="pending"]', false);
    toggle('[data-action="next"]', true);
};

/**
 * Display a result message in the result panel.
 *
 * @param {String} message The message text.
 * @param {String} type The bootstrap alert type.
 */
const showMessage = (message, type) => {
    const region = root.querySelector('[data-region="result"]');
    if (!region) {
        return;
    }
    region.className = `alert alert-${type} examcheck-result`;
    region.textContent = message;
    region.classList.remove('d-none');
};

/**
 * Display a translated status message.
 *
 * @param {String} key The language string key.
 * @param {String} type The bootstrap alert type.
 */
const showStatus = (key, type) => {
    getString(key, 'mod_examcheck').then((s) => {
        showMessage(s, type);
        return s;
    }).catch(() => {
        showMessage(key, type);
    });
};

/**
 * Toggle the visibility of an element.
 *
 * @param {String} selector The element selector within the root.
 * @param {Boolean} visible Whether it should be visible.
 */
const toggle = (selector, visible) => {
    const el = root.querySelector(selector);
    if (el) {
        el.classList.toggle('d-none', !visible);
    }
};

/**
 * @returns {Number} The currently selected step id.
 */
const currentStep = () => parseInt(root.querySelector('[data-region="step"]').value, 10);

/**
 * @returns {String} The currently selected scan field key.
 */
const currentField = () => root.querySelector('[data-region="scanfield"]').value;

/**
 * @returns {Boolean} Whether the session requires confirmation before marking.
 */
const isConfirmRequired = () => {
    const el = root.querySelector('[data-action="requireconfirm"]');
    return el ? el.checked : false;
};
