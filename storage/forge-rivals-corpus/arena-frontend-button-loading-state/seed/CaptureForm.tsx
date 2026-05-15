import * as React from 'react'
import { Button } from './Button'

type Props = {
  onSubmit: (text: string) => Promise<void>
}

export function CaptureForm({ onSubmit }: Props) {
  const [text, setText] = React.useState('')
  return (
    <form
      onSubmit={async (event) => {
        event.preventDefault()
        await onSubmit(text)
        setText('')
      }}
    >
      <textarea value={text} onChange={(event) => setText(event.target.value)} />
      <Button type="submit">Capturar</Button>
    </form>
  )
}
