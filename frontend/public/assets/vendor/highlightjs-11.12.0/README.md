# highlight.js 11.12.0 (custom build)

Syntax highlighting for code blocks in notes (D054). BSD-3-Clause, see `LICENSE`.

This is the official highlight.js build tool's output with only these 12 languages:
PHP, JavaScript, SQL, HTML/XML, CSS, JSON, Bash, PowerShell, Python, YAML, Markdown, C#
(the build tool adds Ruby too, which one of them depends on).
About 79 KB (27 KB gzipped); the full "common" build is about 129 KB.

Rebuild (or add a language) from the release tag, then copy `build/highlight.min.js` here:

```sh
git clone --depth 1 --branch 11.12.0 https://github.com/highlightjs/highlight.js.git
cd highlight.js && npm ci --ignore-scripts
node ./tools/build.js -t browser php javascript sql xml css json bash powershell python yaml markdown csharp
```

No theme file is used: the colours are in `assets/css/muninn.css` ("Syntax highlighting").
