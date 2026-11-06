"""Gera os efeitos originais do Na Quadra, sem amostras externas."""
from array import array
import math
from pathlib import Path
import random
import struct
import wave

RATE = 22_050
DESTINO = Path(__file__).resolve().parents[1] / "public" / "sons"


def salvar(nome: str, amostras: list[float]) -> None:
    pcm = array("h", (round(max(-1, min(1, valor)) * 32767) for valor in amostras))
    if struct.pack("=h", 1) != struct.pack("<h", 1):
        pcm.byteswap()
    with wave.open(str(DESTINO / nome), "wb") as arquivo:
        arquivo.setparams((1, 2, RATE, len(pcm), "NONE", "not compressed"))
        arquivo.writeframes(pcm.tobytes())


def apito() -> None:
    rng = random.Random(20261106)
    ruido = 0.0
    amostras = []
    duracao = 0.90
    for i in range(round(RATE * duracao)):
        t = i / RATE
        # Três sopros curtos, harmonicas agudas e turbulência de um apito de árbitro.
        envelopes = ((0.00, 0.22), (0.27, 0.49), (0.54, 0.88))
        env = 0.0
        for inicio, fim in envelopes:
            if inicio <= t < fim:
                local = (t - inicio) / (fim - inicio)
                env = min(1.0, local * 45, (1 - local) * 30)
                break
        ruido = 0.72 * ruido + 0.28 * rng.uniform(-1, 1)
        vibrato = 35 * math.sin(2 * math.pi * 24 * t)
        freq = 2350 + vibrato
        fase = 2 * math.pi * freq * t
        tom = math.sin(fase) + 0.34 * math.sin(2.01 * fase) + 0.16 * math.sin(3.02 * fase)
        amostras.append(0.52 * env * (0.72 * tom + 0.28 * ruido))
    salvar("apito-inicio.wav", amostras)


def buzzer() -> None:
    amostras = []
    duracao = 1.25
    for i in range(round(RATE * duracao)):
        t = i / RATE
        env = min(1.0, t * 90, (duracao - t) * 6)
        # Fundamental em 55 Hz: buzzer grave, com harmônicos para alto-falantes pequenos.
        fase = 2 * math.pi * 55 * t
        grave = math.sin(fase) + 0.50 * math.sin(2 * fase)
        grave += 0.32 * math.sin(3 * fase) + 0.18 * math.sin(6 * fase)
        rugido = 0.12 * math.tanh(4 * math.sin(2 * math.pi * 110 * t))
        amostras.append(0.64 * env * (grave + rugido))
    salvar("buzzer-fim.wav", amostras)


if __name__ == "__main__":
    DESTINO.mkdir(parents=True, exist_ok=True)
    apito()
    buzzer()
