export default function TestTemplate() {
  return (
    <div id="test_print" className="w-[600px] font-bold">
      <img className="block mx-auto w-[50mm]" src="/images/logo.png" alt="" />
      <p className="text-3xl text-center">Test Work Fine</p>
      <img className="block mx-auto w-[50mm]" src="/images/turbo.png" alt="" />
      <p className="text-xl text-center">Turbo Software Space</p>
      <p className="text-center"> {new Date().toLocaleString('ar-EG', { hour12: true })}</p>
    </div>
  )
}
