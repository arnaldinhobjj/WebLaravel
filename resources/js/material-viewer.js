// Materials page: switch the part viewer between blueprint, 2D drawing, 3D model
// and image. The 2D drawing (DXF) and 3D model (STEP, IGES, glTF, STL, OBJ, 3MF,
// PLY) are drawn with three.js the first time their tab opens.

const STORAGE_KEY = 'fv.material-view';

document.querySelectorAll('[data-material-viewer]').forEach((viewer) => {
    const tabs = [...viewer.querySelectorAll('[data-viewer-tab]')];
    const panels = new Map([...viewer.querySelectorAll('[data-viewer-panel]')].map((panel) => [panel.dataset.viewerPanel, panel]));
    const scenes = new Map();

    const show = (slot, { focus = false, remember = true } = {}) => {
        tabs.forEach((tab) => {
            const selected = tab.dataset.viewerTab === slot;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
            if (selected && focus) tab.focus();
        });
        panels.forEach((panel, key) => { panel.hidden = key !== slot; });
        scenes.forEach((scene, key) => (key === slot ? scene.start() : scene.stop()));

        const stage = panels.get(slot)?.querySelector('[data-model-url]');
        if (stage && !scenes.has(slot)) {
            scenes.set(slot, createScene(stage));
        }
        if (remember) {
            try { localStorage.setItem(STORAGE_KEY, slot); } catch (e) {}
        }
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => show(tab.dataset.viewerTab));
        tab.addEventListener('keydown', (event) => {
            const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            if (event.key === 'Home' || event.key === 'End' || step) {
                event.preventDefault();
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + step + tabs.length) % tabs.length;
                show(tabs[next].dataset.viewerTab, { focus: true });
            }
        });
    });

    // A ?view= link wins; otherwise reopen the view this person used last.
    let initial = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.viewerTab;
    if (!viewer.hasAttribute('data-view-fixed')) {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved && panels.has(saved)) initial = saved;
        } catch (e) {}
    }
    if (initial) show(initial, { remember: false });
});

/**
 * Sets up a three.js scene in `stage` and loads its file. three.js and the
 * loaders are fetched only now, so pages that never open these views don't
 * download them; the STEP/IGES converter (several MB) only for those files.
 */
function createScene(stage) {
    const is2d = stage.dataset.modelKind === '2d';
    let renderer = null;
    let running = false;
    let wanted = true;
    const status = stage.querySelector('[data-model-status]');

    const setStatus = (text) => {
        if (status) {
            status.textContent = text;
            status.hidden = text === '';
        }
    };

    const ready = Promise.all([
        import('three'),
        import('three/addons/controls/OrbitControls.js'),
    ]).then(async ([THREE, { OrbitControls }]) => {
        const scene = new THREE.Scene();
        const camera = is2d ? new THREE.OrthographicCamera(-1, 1, 1, -1, -1000, 1000) : new THREE.PerspectiveCamera(40, 1, 0.01, 1000);
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        stage.prepend(renderer.domElement);

        const controls = new OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        if (is2d) {
            // Pan with either button, zoom with the wheel; no rotating a flat drawing.
            controls.enableRotate = false;
            controls.screenSpacePanning = true;
            controls.mouseButtons = { LEFT: THREE.MOUSE.PAN, MIDDLE: THREE.MOUSE.DOLLY, RIGHT: THREE.MOUSE.PAN };
            controls.touches = { ONE: THREE.TOUCH.PAN, TWO: THREE.TOUCH.DOLLY_PAN };
        } else {
            const { RoomEnvironment } = await import('three/addons/environments/RoomEnvironment.js');
            // A soft studio environment, so metallic parts have something to reflect.
            scene.environment = new THREE.PMREMGenerator(renderer).fromScene(new RoomEnvironment(), 0.04).texture;
            scene.add(new THREE.HemisphereLight(0xffffff, 0x8a94a6, 0.6));
            const key = new THREE.DirectionalLight(0xffffff, 1.8);
            key.position.set(3, 5, 4);
            scene.add(key);
            const fill = new THREE.DirectionalLight(0xffffff, 0.6);
            fill.position.set(-4, 2, -3);
            scene.add(fill);
        }

        let fitted = null;
        const resize = () => {
            const { clientWidth: width, clientHeight: height } = stage;
            if (!width || !height) return;
            renderer.setSize(width, height, false);
            if (is2d) {
                if (fitted) fitted();
            } else {
                camera.aspect = width / height;
                camera.updateProjectionMatrix();
            }
        };
        new ResizeObserver(resize).observe(stage);
        resize();

        const response = await fetch(stage.dataset.modelUrl, { credentials: 'same-origin' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const buffer = await response.arrayBuffer();
        const format = stage.dataset.modelFormat;

        if (['step', 'stp', 'iges', 'igs'].includes(format)) {
            setStatus('Converting CAD model…');
            await new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve)));
        }
        const object = is2d
            ? await loadDrawing(THREE, buffer, getComputedStyle(stage).color)
            : await loadModel(THREE, format, buffer);

        scene.add(object);
        const box = new THREE.Box3().setFromObject(object);
        if (box.isEmpty()) throw new Error('Empty model');
        const size = box.getSize(new THREE.Vector3());
        const center = box.getCenter(new THREE.Vector3());
        object.position.sub(center);

        if (is2d) {
            // Fit the drawing with a small margin, keeping its proportions.
            fitted = () => {
                const aspect = stage.clientWidth / stage.clientHeight || 1;
                const halfHeight = Math.max(size.y, size.x / aspect, 1e-6) * 0.55;
                camera.left = -halfHeight * aspect;
                camera.right = halfHeight * aspect;
                camera.top = halfHeight;
                camera.bottom = -halfHeight;
                camera.updateProjectionMatrix();
            };
            fitted();
            camera.position.set(0, 0, 10);
            controls.target.set(0, 0, 0);
        } else {
            // Fit the bounding sphere, so the part fits from any angle it is turned to.
            const radius = (size.length() / 2) || 1;
            const fov = THREE.MathUtils.degToRad(camera.fov);
            const distance = (radius / Math.sin(Math.min(fov, 2 * Math.atan(Math.tan(fov / 2) * camera.aspect)) / 2)) * 1.05;
            camera.near = distance / 100;
            camera.far = distance * 100;
            camera.position.set(distance * 0.7, distance * 0.5, distance * 0.7);
            camera.updateProjectionMatrix();
            controls.target.set(0, 0, 0);
        }
        controls.update();
        setStatus('');

        return { renderer, scene, camera, controls };
    });

    const start = () => {
        wanted = true;
        ready.then(({ renderer: r, scene, camera, controls }) => {
            if (!wanted || running) return;
            running = true;
            r.setAnimationLoop(() => {
                controls.update();
                r.render(scene, camera);
            });
        }).catch(() => {});
    };

    const stop = () => {
        wanted = false;
        running = false;
        renderer?.setAnimationLoop(null);
    };

    ready.catch(() => setStatus(is2d
        ? 'The drawing couldn’t be shown. Download it to open it in a CAD program.'
        : 'The 3D model couldn’t be shown. Download it to open it in a CAD viewer.'));
    start();

    return { start, stop };
}

/** A three.js object for a 3D model file, by its extension. */
async function loadModel(THREE, format, buffer) {
    const metal = () => new THREE.MeshStandardMaterial({ color: 0x9aa4b2, metalness: 0.6, roughness: 0.4, side: THREE.DoubleSide });
    const meshOf = (geometry) => {
        if (!geometry.hasAttribute('normal')) {
            // Without normals, shared corners would blend into soft blobs: shade each face flat.
            geometry = geometry.index ? geometry.toNonIndexed() : geometry;
            geometry.computeVertexNormals();
        }
        return new THREE.Mesh(geometry, metal());
    };

    switch (format) {
        case 'step':
        case 'stp':
        case 'iges':
        case 'igs':
            return loadCad(THREE, format, buffer);
        case 'stl': {
            const { STLLoader } = await import('three/addons/loaders/STLLoader.js');
            return meshOf(new STLLoader().parse(buffer));
        }
        case 'ply': {
            const { PLYLoader } = await import('three/addons/loaders/PLYLoader.js');
            const geometry = new PLYLoader().parse(buffer);
            const mesh = meshOf(geometry);
            if (geometry.hasAttribute('color')) mesh.material.vertexColors = true;
            return mesh;
        }
        case 'obj': {
            const { OBJLoader } = await import('three/addons/loaders/OBJLoader.js');
            // Materials (.mtl) live in a separate file we don't have, so use one finish.
            const group = new OBJLoader().parse(new TextDecoder().decode(buffer));
            group.traverse((child) => {
                if (child.isMesh) {
                    if (!child.geometry.hasAttribute('normal')) child.geometry.computeVertexNormals();
                    child.material = metal();
                }
            });
            return group;
        }
        case '3mf': {
            const { ThreeMFLoader } = await import('three/addons/loaders/3MFLoader.js');
            return new ThreeMFLoader().parse(buffer);
        }
        default: {
            const { GLTFLoader } = await import('three/addons/loaders/GLTFLoader.js');
            const gltf = await new GLTFLoader().parseAsync(buffer, '');
            return gltf.scene;
        }
    }
}

/**
 * STEP and IGES are solid (B-rep) models: OpenCascade, compiled to
 * WebAssembly, turns them into triangle meshes with their CAD colours.
 */
async function loadCad(THREE, format, buffer) {
    const [{ default: occtImport }, { default: wasmUrl }] = await Promise.all([
        import('occt-import-js'),
        import('occt-import-js/dist/occt-import-js.wasm?url'),
    ]);
    const occt = await occtImport({ locateFile: () => wasmUrl });
    const read = ['iges', 'igs'].includes(format) ? occt.ReadIgesFile : occt.ReadStepFile;
    const result = read(new Uint8Array(buffer), null);
    if (!result.success || !result.meshes.length) throw new Error('No geometry in the CAD file');

    const group = new THREE.Group();
    group.rotation.x = -Math.PI / 2; // CAD files are Z-up; three.js is Y-up.
    for (const part of result.meshes) {
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(part.attributes.position.array, 3));
        if (part.attributes.normal) {
            geometry.setAttribute('normal', new THREE.Float32BufferAttribute(part.attributes.normal.array, 3));
        } else {
            geometry.computeVertexNormals();
        }
        geometry.setIndex(part.index.array);

        // Faces may carry their own colours; IGES "no colour" arrives as black.
        const material = (rgb) => new THREE.MeshStandardMaterial({
            color: rgb && rgb.some((c) => c > 0) ? new THREE.Color(...rgb) : new THREE.Color(0x9aa4b2),
            metalness: 0.5,
            roughness: 0.45,
            side: THREE.DoubleSide,
        });
        const faces = part.brep_faces ?? [];
        if (faces.some((face) => face.color)) {
            const materials = [material(part.color)];
            for (const face of faces) {
                materials.push(material(face.color ?? part.color));
                geometry.addGroup(face.first * 3, (face.last - face.first + 1) * 3, materials.length - 1);
            }
            group.add(new THREE.Mesh(geometry, materials));
        } else {
            group.add(new THREE.Mesh(geometry, material(part.color)));
        }
    }
    return group;
}

/**
 * A DXF drawing as line segments in the page's text colour: lines,
 * polylines (with arcs), circles, arcs, ellipses, splines and block
 * references (including dimensions). Text is not drawn.
 */
async function loadDrawing(THREE, buffer, color) {
    const { default: DxfParser } = await import('dxf-parser');
    const dxf = new DxfParser().parseSync(new TextDecoder().decode(buffer));
    const points = [];
    const segment = (a, b) => points.push(a.x, a.y, 0, b.x, b.y, 0);
    const path = (list, closed) => {
        for (let i = 1; i < list.length; i++) segment(list[i - 1], list[i]);
        if (closed && list.length > 2) segment(list[list.length - 1], list[0]);
    };
    const arcPoints = (cx, cy, r, start, end) => {
        let sweep = end - start;
        while (sweep <= 0) sweep += Math.PI * 2;
        const steps = Math.max(8, Math.ceil(sweep / (Math.PI / 36)));
        return Array.from({ length: steps + 1 }, (_, i) => {
            const a = start + (sweep * i) / steps;
            return { x: cx + r * Math.cos(a), y: cy + r * Math.sin(a) };
        });
    };
    // A polyline segment with a bulge is an arc: bulge = tan(sweep / 4).
    const bulgePoints = (a, b, bulge) => {
        const sweep = 4 * Math.atan(bulge);
        const chord = Math.hypot(b.x - a.x, b.y - a.y);
        const r = chord / (2 * Math.sin(Math.abs(sweep) / 2));
        const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
        const offset = Math.sqrt(Math.max(r * r - (chord / 2) ** 2, 0)) * Math.sign(bulge) * (Math.abs(sweep) > Math.PI ? -1 : 1);
        const cx = mid.x - (offset * (b.y - a.y)) / chord;
        const cy = mid.y + (offset * (b.x - a.x)) / chord;
        const start = Math.atan2(a.y - cy, a.x - cx);
        const steps = Math.max(4, Math.ceil(Math.abs(sweep) / (Math.PI / 36)));
        return Array.from({ length: steps + 1 }, (_, i) => {
            const t = start + (sweep * i) / steps;
            return { x: cx + r * Math.cos(t), y: cy + r * Math.sin(t) };
        });
    };

    const draw = (entities, transform, depth = 0) => {
        for (const entity of entities ?? []) {
            const at = (p) => transform({ x: p.x ?? 0, y: p.y ?? 0 });
            switch (entity.type) {
                case 'LINE':
                    if (entity.vertices?.length >= 2) segment(at(entity.vertices[0]), at(entity.vertices[1]));
                    break;
                case 'LWPOLYLINE':
                case 'POLYLINE': {
                    const vertices = entity.vertices ?? [];
                    const closed = entity.shape || entity.closed;
                    const list = [];
                    vertices.forEach((vertex, i) => {
                        const next = vertices[i + 1] ?? (closed ? vertices[0] : null);
                        if (next && vertex.bulge) {
                            list.push(...bulgePoints(vertex, next, vertex.bulge).slice(0, -1));
                        } else {
                            list.push(vertex);
                        }
                    });
                    if (!closed && vertices.length) list.push(vertices[vertices.length - 1]);
                    path(list.map(at), closed);
                    break;
                }
                case 'CIRCLE':
                    path(arcPoints(entity.center.x, entity.center.y, entity.radius, 0, Math.PI * 2).map(at), false);
                    break;
                case 'ARC':
                    path(arcPoints(entity.center.x, entity.center.y, entity.radius, entity.startAngle, entity.endAngle).map(at), false);
                    break;
                case 'ELLIPSE': {
                    const major = entity.majorAxisEndPoint;
                    const rx = Math.hypot(major.x, major.y);
                    const rotation = Math.atan2(major.y, major.x);
                    const start = entity.startAngle ?? 0;
                    let end = entity.endAngle ?? Math.PI * 2;
                    if (end <= start) end += Math.PI * 2;
                    const steps = 72;
                    const list = Array.from({ length: steps + 1 }, (_, i) => {
                        const t = start + ((end - start) * i) / steps;
                        const x = rx * Math.cos(t);
                        const y = rx * entity.axisRatio * Math.sin(t);
                        return {
                            x: entity.center.x + x * Math.cos(rotation) - y * Math.sin(rotation),
                            y: entity.center.y + x * Math.sin(rotation) + y * Math.cos(rotation),
                        };
                    });
                    path(list.map(at), false);
                    break;
                }
                case 'SPLINE': {
                    const through = entity.fitPoints?.length > 1 ? entity.fitPoints : entity.controlPoints ?? [];
                    if (through.length > 1) {
                        const curve = new THREE.CatmullRomCurve3(through.map((p) => new THREE.Vector3(p.x, p.y, 0)));
                        path(curve.getPoints(Math.max(32, through.length * 8)).map(at), false);
                    }
                    break;
                }
                case 'INSERT':
                case 'DIMENSION': {
                    const block = dxf.blocks?.[entity.name ?? entity.block];
                    if (!block || depth > 8) break;
                    const angle = ((entity.rotation ?? 0) * Math.PI) / 180;
                    const sx = entity.xScale ?? 1;
                    const sy = entity.yScale ?? 1;
                    const position = entity.type === 'INSERT' ? (entity.position ?? { x: 0, y: 0 }) : { x: 0, y: 0 };
                    const base = block.position ?? { x: 0, y: 0 };
                    draw(block.entities, (p) => {
                        const x = (p.x - base.x) * sx;
                        const y = (p.y - base.y) * sy;
                        return transform({
                            x: position.x + x * Math.cos(angle) - y * Math.sin(angle),
                            y: position.y + x * Math.sin(angle) + y * Math.cos(angle),
                        });
                    }, depth + 1);
                    break;
                }
                default:
                    break;
            }
        }
    };

    draw(dxf.entities, (p) => p);
    if (!points.length) throw new Error('No drawable entities in the DXF');

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(points, 3));
    return new THREE.LineSegments(geometry, new THREE.LineBasicMaterial({ color: new THREE.Color(color) }));
}
