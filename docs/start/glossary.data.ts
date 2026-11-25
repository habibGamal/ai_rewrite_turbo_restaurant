import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineLoader } from 'vitepress'

export interface GlossaryEntry {
  term: string
  meaning: string
}

export interface GlossaryData {
  entries: GlossaryEntry[]
}

declare const data: GlossaryData
export { data }

const glossaryPath = resolve(dirname(fileURLToPath(import.meta.url)), '../../.workspace/GLOSSARY.md')

function parseGlossary(markdown: string): GlossaryEntry[] {
  const entries: GlossaryEntry[] = []

  for (const line of markdown.split(/\r?\n/)) {
    const trimmed = line.trim()
    if (!trimmed.startsWith('|')) continue

    const cells = trimmed
      .slice(1, -1)
      .split('|')
      .map((cell) => cell.trim())

    if (cells.length < 4) continue
    if (cells[0] === 'English code name' || /^-+$/.test(cells[0].replace(/[:\s]/g, ''))) {
      continue
    }

    const term = cells[1].replace(/\s*\(never [^)]*\)/g, '').trim()
    const meaning = cells[3].trim()

    if (term && meaning) {
      entries.push({ term, meaning })
    }
  }

  return entries
}

export default defineLoader({
  watch: ['../../.workspace/GLOSSARY.md'],
  load(): GlossaryData {
    const markdown = readFileSync(glossaryPath, 'utf-8')
    return {
      entries: parseGlossary(markdown),
    }
  },
})
