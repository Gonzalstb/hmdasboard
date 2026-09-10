# Sites Worker ESM starter

Use this starter for a static microsite, click counter, or simple internal UI whose state is browser-scoped. It has no dependencies and needs no install.

The application code lives under `worker/` (entry: `worker/index.js`). Architecture, routes and folders are documented in [`DOCUMENTACION.md`](./DOCUMENTACION.md). Use the Sites checkpoint when a coherent milestone is ready to inspect or share; the remote builder then runs the checked-in build and validation scripts. Do not run them as a normal pre-checkpoint step.

The build bundles `worker/` into `dist/server/index.js` and copies `.openai/hosting.json`. Do not add standalone public asset files; the UI is embedded and served by the Worker.

For targeted diagnosis after a remote build failure, the same commands are available in the Sites Linux environment:

```sh
bash scripts/build.sh
node scripts/validate-artifact.mjs
```

The deterministic build produces:

```text
dist/
├── .openai/
│   └── hosting.json
└── server/
    └── index.js
```

`dist/server/index.js` is an ES module with a default export containing `fetch(request, env, ctx)`. Edit `worker/index.js`, not the generated file under `dist/`.
