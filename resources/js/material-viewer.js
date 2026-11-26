// Materials page: switch the part viewer between blueprint, 3D model and image,
// and show the 3D model with three.js the first time its tab opens.

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
 * Sets up a three.js scene in `stage` and loads its model. three.js is loaded
 * only now, so pages that never open the 3D view don't download it.
 */
function createScene(stage) {
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
        import('three/addons/loaders/GLTFLoader.js'),
        import('three/addons/loaders/STLLoader.js'),
        import('three/addons/environments/RoomEnvironment.js'),
    ]).then(([THREE, { OrbitControls }, { GLTFLoader }, { STLLoader }, { RoomEnvironment }]) => {
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(40, 1, 0.01, 1000);
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        stage.prepend(renderer.domElement);

        // A soft studio environment, so metallic parts have something to reflect.
        scene.environment = new THREE.PMREMGenerator(renderer).fromScene(new RoomEnvironment(), 0.04).texture;
        scene.add(new THREE.HemisphereLight(0xffffff, 0x8a94a6, 0.6));
        const key = new THREE.DirectionalLight(0xffffff, 1.8);
        key.position.set(3, 5, 4);
        scene.add(key);
        const fill = new THREE.DirectionalLight(0xffffff, 0.6);
        fill.position.set(-4, 2, -3);
        scene.add(fill);

        const controls = new OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;

        const resize = () => {
            const { clientWidth: width, clientHeight: height } = stage;
            if (!width || !height) return;
            renderer.setSize(width, height, false);
            camera.aspect = width / height;
            camera.updateProjectionMatrix();
        };
        new ResizeObserver(resize).observe(stage);
        resize();

        const fit = (object) => {
            const box = new THREE.Box3().setFromObject(object);
            const size = box.getSize(new THREE.Vector3());
            const center = box.getCenter(new THREE.Vector3());
            object.position.sub(center);
            const radius = Math.max(size.x, size.y, size.z) || 1;
            const distance = radius / (2 * Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2)) * 1.6;
            camera.near = distance / 100;
            camera.far = distance * 100;
            camera.position.set(distance * 0.7, distance * 0.5, distance * 0.7);
            camera.updateProjectionMatrix();
            controls.target.set(0, 0, 0);
            controls.update();
        };

        const url = stage.dataset.modelUrl;
        const format = stage.dataset.modelFormat;
        const loaded = format === 'stl'
            ? new STLLoader().loadAsync(url).then((geometry) => {
                geometry.computeVertexNormals();
                return new THREE.Mesh(geometry, new THREE.MeshStandardMaterial({ color: 0x9aa4b2, metalness: 0.6, roughness: 0.4 }));
            })
            : new GLTFLoader().loadAsync(url).then((gltf) => gltf.scene);

        return loaded.then((object) => {
            scene.add(object);
            fit(object);
            setStatus('');
            return { renderer, scene, camera, controls };
        });
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

    ready.catch(() => setStatus('The 3D model couldn’t be shown. Download it to open it in a CAD viewer.'));
    start();

    return { start, stop };
}
