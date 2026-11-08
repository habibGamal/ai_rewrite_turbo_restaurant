import type { KdsSound } from '@/types/kitchen';

type OscillatorKind = OscillatorType;

function tone(
    ctx: AudioContext,
    destination: AudioNode,
    frequency: number,
    when: number,
    duration: number,
    gain: number,
    type: OscillatorKind,
    decay: boolean,
): void {
    const osc = ctx.createOscillator();
    const envelope = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(frequency, when);
    envelope.gain.setValueAtTime(0.0001, when);
    envelope.gain.exponentialRampToValueAtTime(Math.max(0.0001, gain), when + 0.012);
    if (decay) {
        envelope.gain.exponentialRampToValueAtTime(0.0001, when + duration);
    } else {
        envelope.gain.setValueAtTime(Math.max(0.0001, gain), when + Math.max(0.02, duration - 0.03));
        envelope.gain.exponentialRampToValueAtTime(0.0001, when + duration);
    }
    osc.connect(envelope);
    envelope.connect(destination);
    osc.start(when);
    osc.stop(when + duration + 0.02);
}

/**
 * Four short patterns, distinct even on a laptop speaker in a loud kitchen:
 * chime = rising sine triad, bell = decaying harmonic, beep = two square blips,
 * alert = fast alternating sawtooth pulses.
 */
export function playKdsSound(ctx: AudioContext, sound: KdsSound, volumePercent: number): void {
    if (sound === 'none' || volumePercent <= 0) {
        return;
    }
    const master = ctx.createGain();
    const level = Math.max(0, Math.min(1, volumePercent / 100));
    master.gain.value = level * 0.55;
    master.connect(ctx.destination);
    const now = ctx.currentTime;

    if (sound === 'chime') {
        tone(ctx, master, 523.25, now, 0.16, 0.7, 'sine', false);
        tone(ctx, master, 659.25, now + 0.14, 0.18, 0.75, 'sine', false);
        tone(ctx, master, 783.99, now + 0.28, 0.34, 0.65, 'sine', true);
        return;
    }

    if (sound === 'bell') {
        tone(ctx, master, 880, now, 0.85, 0.9, 'sine', true);
        tone(ctx, master, 1760, now, 0.45, 0.28, 'sine', true);
        tone(ctx, master, 2637, now, 0.28, 0.08, 'sine', true);
        return;
    }

    if (sound === 'beep') {
        tone(ctx, master, 988, now, 0.09, 0.55, 'square', false);
        tone(ctx, master, 988, now + 0.16, 0.09, 0.55, 'square', false);
        return;
    }

    for (let index = 0; index < 5; index += 1) {
        tone(ctx, master, index % 2 === 0 ? 960 : 620, now + index * 0.13, 0.1, 0.7, 'sawtooth', false);
    }
}
