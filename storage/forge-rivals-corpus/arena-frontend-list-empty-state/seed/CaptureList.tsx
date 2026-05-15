import * as React from 'react'

type Capture = { id: string; text: string }

type Props = {
  items: Capture[]
  activeFilter?: string
  onResetFilter?: () => void
}

export function CaptureList({ items }: Props) {
  return (
    <ul>
      {items.map((capture) => (
        <li key={capture.id}>{capture.text}</li>
      ))}
    </ul>
  )
}
