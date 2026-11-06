"""Gera os efeitos sonoros do Na Quadra.

O arquivo `public/sons/apito-inicio.wav` padrão provém de uma gravação real de
apito de árbitro em domínio público (CC0 1.0) no Wikimedia Commons:
https://commons.wikimedia.org/wiki/File:218318_splicesound_referee-whistle-blow-gymnasium.wav
Passe `--sintetizar-apito` apenas se quiser recriar um apito de ervilha sintético.
"""
from array import array
import math
from pathlib import Path
import random
import struct
import sys
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
    bp_prev1 = 0.0
    bp_prev2 = 0.0
    amostras = []
    duracao = 0.95
    fase1 = 0.0
    fase2 = 0.0
    for i in range(round(RATE * duracao)):
        t = i / RATE
        ataque = min(1.0, t / 0.008)
        cauda = min(1.0, max(0.0, (duracao - t) / 0.14))
        env = ataque * cauda
        # Trilo rápido da ervilha (~30 Hz) modulando amplitude e frequência.
        trilo = 0.68 + 0.32 * math.sin(2 * math.pi * 30.0 * t + 0.25 * math.sin(2 * math.pi * 7.5 * t))
        f1 = 2840.0 + 55.0 * trilo
        f2 = 3120.0 + 62.0 * trilo
        fase1 += 2 * math.pi * f1 / RATE
        fase2 += 2 * math.pi * f2 / RATE
        tom = 0.62 * math.sin(fase1) + 0.38 * math.sin(fase2) + 0.18 * math.sin(2 * fase1)
        # Ruído de sopro filtrado em torno da faixa aguda do apito.
        branco = rng.uniform(-1.0, 1.0)
        bp = 0.22 * branco + 0.52 * bp_prev1 - 0.38 * bp_prev2
        bp_prev2 = bp_prev1
        bp_prev1 = bp
        amostras.append(0.78 * env * (trilo * tom + 0.22 * bp))
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
    if "--sintetizar-apito" in sys.argv or not (DESTINO / "apito-inicio.wav").exists():
        apito()
    buzzer()
