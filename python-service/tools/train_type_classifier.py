"""Addestra il classificatore dei tipi (app/core/typeclass.py) su MAR20.

MAR20 (Northwestern Polytechnical University, licenza CC BY-NC 4.0: solo uso
non commerciale e di ricerca, citando l'articolo) si scarica a mano dalla
pagina degli autori, https://gcheng-nwpu.github.io/ (OneDrive o Baidu):
MAR20.zip, 1.207.288.219 byte, SHA-256
1a496875c1c034218a2bff9feb01f693911ca13cd63dc8f9ee7a79eecdcb4825. Va estratto
FUORI dal sito web e dal repository; né il dataset né il risultato vanno
pubblicati.

Serve il modello DINOv2 della libreria visiva (fetch_embedding_model.sh): i
vettori sono gli stessi che il servizio calcola sugli aerei rilevati, con lo
stesso ritaglio (embed.crop). I riquadri di MAR20 sono stretti sull'aereo:
la dimensione è il lato maggiore. Quattro orientamenti per aereo.

Uso (dalla cartella python-service, con il venv del servizio):
    venv/bin/python tools/train_type_classifier.py /percorso/MAR20

Campione: fino a 150 aerei per tipo dall'insieme di addestramento e 60 da
quello di prova (per la valutazione), presi a turno fra le immagini. Con un
processo a 4 thread, circa 0,1–0,3 s per ritaglio: un'ora o due. I vettori
restano in cache nella cartella del dataset (un file per insieme, campione e
modello dei vettori): riaddestrare è questione di secondi. Risultato:
models/mar20-types.npz; poi si riavvia il servizio di analisi.
"""
import argparse
import base64
import random
import sys
import time
import xml.etree.ElementTree as ET
from collections import defaultdict
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from app.core import embed, typeclass  # noqa: E402

# Codici di MAR20 (A1..A20) → nomi del catalogo dei tipi
# (webapp/src/aircraft_types.json), verificati a vista sui ritagli.
NAMES = ["Su-35", "C-130 Hercules", "C-17 Globemaster III", "C-5 Galaxy", "F-16 Fighting Falcon", "Tu-160",
         "E-3 Sentry", "B-52 Stratofortress", "P-3 Orion", "B-1 Lancer", "E-8 Joint STARS", "Tu-22M",
         "F-15 Eagle", "KC-135 Stratotanker", "F-22 Raptor", "F/A-18 Hornet", "Tu-95", "KC-10 Extender",
         "Su-34", "Su-24"]
CORNERS = ["x_left_top", "y_left_top", "x_right_top", "y_right_top",
           "x_right_bottom", "y_right_bottom", "x_left_bottom", "y_left_bottom"]
MIN_SIZE_PX = 12


def objects(root: Path, split: str) -> dict:
    """{codice: {immagine: [vertici 4×2, ...]}}"""
    out = defaultdict(lambda: defaultdict(list))
    for i in (root / "ImageSets" / "Main" / f"{split}.txt").read_text().split():
        xml = ET.parse(root / "Annotations" / "Oriented Bounding Boxes" / f"{i}.xml").getroot()
        for o in xml.iter("object"):
            rb = o.find("robndbox")
            p = np.array([float(rb.findtext(k)) for k in CORNERS], np.float32).reshape(4, 2)
            out[o.findtext("name")][i].append(p)
    return out


def sample(by_class: dict, n: int, seed: int) -> list:
    """Fino a n oggetti per tipo, a turno fra le immagini (più immagini diverse possibile)."""
    rnd = random.Random(seed)
    out = []
    for cls in sorted(by_class, key=lambda c: int(c[1:])):
        imgs = by_class[cls]
        keys = sorted(imgs)
        rnd.shuffle(keys)
        pools = {k: rnd.sample(imgs[k], len(imgs[k])) for k in keys}
        picked = []
        while len(picked) < n and any(pools.values()):
            for k in keys:
                if pools[k] and len(picked) < n:
                    picked.append((k, pools[k].pop()))
        out += [(cls, k, p) for k, p in picked]
    return sorted(out, key=lambda t: t[1])  # per immagine: ognuna letta una volta


def features(root: Path, split: str, n: int, seed: int) -> dict:
    # La cache vale per lo stesso insieme, campione e modello dei vettori.
    cache = root / f"features_{split}_{n}_{seed}_{embed.MODEL_NAME}.npz"
    if cache.is_file():
        d = np.load(cache)
        return {k: d[k] for k in ("X", "y", "ori")}
    items = sample(objects(root, split), n, seed)
    rows, img_name, im, started = [], None, None, time.time()
    for j, (cls, name, p) in enumerate(items):
        if name != img_name:
            img_name, im = name, cv2.imread(str(root / "JPEGImages" / f"{name}.jpg"))
            if im is None:
                print(f"Immagine mancante o illeggibile: {name}.jpg, saltata", flush=True)
        if im is None:
            continue
        (cx, cy), (w, h), a = cv2.minAreaRect(p)
        size = max(w, h)
        if size < MIN_SIZE_PX:
            continue
        base = a if w >= h else a + 90
        for k, ang in enumerate((base, base + 90, base + 180, base + 270)):
            v = embed.vector(embed.crop(im, (cx, cy), ang, size, 1.0))
            rows.append((np.frombuffer(base64.b64decode(embed.encode(v)), np.int8), int(cls[1:]), k))
        if j % 200 == 0:
            print(f"{split}: {j + 1}/{len(items)} aerei, {time.time() - started:.0f} s", flush=True)
    if not rows:
        sys.exit(f"Nessun aereo utilizzabile nell'insieme {split}: controlla la cartella del dataset")
    d = {"X": np.stack([r[0] for r in rows]), "y": np.array([r[1] for r in rows]), "ori": np.array([r[2] for r in rows])}
    np.savez_compressed(cache, **d)
    return d


def normalized(d: dict):
    X = d["X"].astype(np.float32)
    X /= np.linalg.norm(X, axis=1, keepdims=True)
    return X, d["y"] - 1, d["ori"]


def softmax(z):
    z = z - z.max(1, keepdims=True)
    e = np.exp(z)
    return e / e.sum(1, keepdims=True)


def fit(X, y, C=20, lam=1e-3, epochs=600, lr=0.5):
    """Regressione softmax a discesa del gradiente; i tipi rari pesano quanto i frequenti."""
    n, dim = X.shape
    W = np.zeros((dim, C), np.float32)
    b = np.zeros(C, np.float32)
    Y = np.eye(C, dtype=np.float32)[y]
    cw = (n / (C * np.bincount(y, minlength=C).clip(1))).astype(np.float32)[y][:, None]
    for _ in range(epochs):
        G = (softmax(X @ W + b) - Y) * cw / n
        W -= lr * (X.T @ G + lam * W)
        b -= lr * G.sum(0)
    return W, b


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("dataset", type=Path, help="cartella di MAR20 estratto (con JPEGImages, Annotations, ImageSets)")
    ap.add_argument("--per-type", type=int, default=150)
    ap.add_argument("--test-per-type", type=int, default=60)
    ap.add_argument("--threads", type=int, default=4)
    ap.add_argument("--out", type=Path, default=typeclass.MODEL_PATH)
    args = ap.parse_args()
    if not embed.available():
        sys.exit("Manca il modello DINOv2: esegui prima tools/fetch_embedding_model.sh")
    cv2.setNumThreads(args.threads)

    Xtr, ytr, _ = normalized(features(args.dataset, "train", args.per_type, 1))
    mu, sd = Xtr.mean(0), Xtr.std(0) + 1e-6
    W, b = fit((Xtr - mu) / sd, ytr)

    Xte, yte, ote = normalized(features(args.dataset, "test", args.test_per_type, 2))
    starts = np.nonzero(ote == 0)[0]  # righe consecutive 0..3 = stesso aereo
    P = np.add.reduceat(softmax(((Xte - mu) / sd) @ W + b), starts, axis=0)
    P /= P.sum(1, keepdims=True)
    yt = yte[starts]
    top1 = float(np.mean(P.argmax(1) == yt))
    top3 = float(np.mean((np.argsort(-P, 1)[:, :3] == yt[:, None]).any(1)))
    print(f"Insieme di prova: {len(yt)} aerei, primo posto {top1:.1%}, fra i primi tre {top3:.1%}")
    # Con la sagoma il servizio calcola due orientamenti (asse e asse+180°):
    # la media su due viste rende le probabilità più sicure.
    Pv = softmax(((Xte - mu) / sd) @ W + b)
    for views in ((0, 1, 2, 3), (0, 2), (1, 3)):
        Q = sum(Pv[starts + v] for v in views) / len(views)
        pred, conf = Q.argmax(1), Q.max(1)
        line = [f"orientamenti {views}: primo posto {np.mean(pred == yt):.1%}"]
        for th in (0.7, 0.9):
            m = conf >= th
            line.append(f"p>={th}: {m.mean():.0%} degli aerei, giusti {np.mean(pred[m] == yt[m]):.1%}")
        print("  " + "; ".join(line))

    args.out.parent.mkdir(parents=True, exist_ok=True)
    np.savez(args.out, W=W, b=b, mu=mu.astype(np.float32), sd=sd.astype(np.float32), names=np.array(NAMES),
             embedding_model=np.array(embed.MODEL_NAME), top1=top1, top3=top3)
    print(f"Salvato {args.out}")


if __name__ == "__main__":
    main()
