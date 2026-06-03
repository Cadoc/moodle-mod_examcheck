zxing-wasm — bundled WebAssembly decoder used by the scanner to read QR codes
and common 1D/2D barcodes from the device camera. Works on every desktop and
mobile browser the plugin targets (Chrome, Edge, Firefox, Safari on iOS and
macOS, Chromium-based mobile browsers).

Upstream: https://github.com/Sec-ant/zxing-wasm  (npm: zxing-wasm)
Version:  3.1.0
License:  Apache-2.0 (glue) over BSD-3-Clause (upstream zxing-cpp)

The library ships in two pieces. Both are vendored in the plugin:

  amd/src/zxingwasm.js      The JavaScript loader/glue (IIFE browser build),
                            compiled by grunt amd into amd/build/zxingwasm.min.js.
  wasm/zxing_reader.wasm    The ~1 MB WebAssembly binary that does the actual
                            decoding. Fetched at runtime by zxingwasm.js the
                            first time the camera is started.

amd/src/zxingwasm.js is the upstream IIFE browser build
(zxing-wasm@3.1.0/dist/iife/reader/index.js) with two local changes:

1. The leading `var ZXingWASM=` was replaced with `window.ZXingWASM=` so the
   global is reliably attached regardless of the AMD wrapper grunt amd puts
   around the file. (A bare `var` declaration would otherwise be module-scoped
   after wrapping, hiding the API from the sibling scanner module.)
2. A trailing `export default window.ZXingWASM;` was appended so a sibling AMD
   module can import it (e.g. `import ZXingWASM from 'mod_examcheck/zxingwasm';`).

wasm/zxing_reader.wasm is vendored verbatim, byte-for-byte, from
zxing-wasm@3.1.0/dist/reader/zxing_reader.wasm. It is **not** processed by
grunt amd — it sits outside amd/ so the build pipeline never touches it, and
it is served by the web server as a regular static file.

The JS module passes the runtime URL of the .wasm file to the decoder via
`setZXingModuleOverrides({ locateFile })`, where the URL is built from
`M.cfg.wwwroot`. No PHP plumbing is needed.

Both files are declared in ../../thirdpartylibs.xml so grunt excludes them
from ESLint; grunt amd still compiles zxingwasm.js to amd/build/zxingwasm.min.js.

Web-server MIME type
--------------------
The browser fetches /mod/examcheck/wasm/zxing_reader.wasm as a regular static
asset and instantiates it with WebAssembly.instantiate(ArrayBuffer), so a
missing or wrong MIME type does NOT break decoding — Apache and nginx default
configurations on any reasonably current distribution already serve .wasm as
`application/wasm` and need no extra config.

If you run a hardened/minimal server config and downloads of .wasm appear to
be 404 or are served as the wrong content type, add the mapping yourself:

  Apache (mod_mime):   AddType application/wasm .wasm
  Nginx:               types { application/wasm wasm; }

The decoder does not require WebAssembly.instantiateStreaming (which would
need the correct MIME type to work), so even on a misconfigured server the
scanner will keep functioning.

To upgrade
----------
1. Download the matching IIFE bundle and the matching .wasm binary for the
   new version (note: both files must come from the SAME upstream release —
   the JS glue and the WebAssembly binary share an internal contract):

   https://cdn.jsdelivr.net/npm/zxing-wasm@<version>/dist/iife/reader/index.js
   https://cdn.jsdelivr.net/npm/zxing-wasm@<version>/dist/reader/zxing_reader.wasm

2. Re-apply the two local changes above to the IIFE file.
3. Replace amd/src/zxingwasm.js and wasm/zxing_reader.wasm.
4. Bump the version here, in ../../thirdpartylibs.xml (both <library> entries)
   and in ../../version.php / CHANGELOG.md.
5. Run grunt amd.
